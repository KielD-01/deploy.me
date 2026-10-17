<?php

namespace App\Platform\Ssh;

use App\Platform\Ssh\Exceptions\SshOutputTooLarge;
use App\Platform\Ssh\Exceptions\SshTimeout;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Runs remote scripts through the system OpenSSH client, so everything in the
 * user's ssh config (aliases, keys, ProxyJump) works exactly as on their Mac.
 */
final class ProcessSshClient implements SshClient
{
    public function __construct(
        private readonly SshConfigCompiler $compiler,
        private readonly KnownHosts $knownHosts,
    ) {}

    public function run(string $alias, RemoteScript $script, ?callable $onLine = null, int $timeout = 60, ?callable $onTick = null): SshResult
    {
        SshAlias::assertValid($alias);

        $config = $this->compiler->compile();
        $this->knownHosts->materialize();

        $buffer = new LineBuffer;
        $limit = (int) config('deployme.ssh.max_output_bytes');
        $printed = 0;
        $tooMuch = false;
        $process = null;
        $callback = function (string $type, string $chunk) use ($buffer, $onLine, $limit, &$printed, &$tooMuch, &$process): void {
            if ($tooMuch) {
                return;
            }

            $printed += strlen($chunk);

            if ($onLine !== null) {
                foreach ($buffer->push($type, $chunk) as [$stream, $line]) {
                    $onLine($line, $stream);
                }
            }

            // A script that never stops printing would fill the worker's
            // memory: the process object keeps everything it has said. Stopped
            // here rather than by throwing, since an exception in this callback
            // drops the rest of the chunks read in the same pass.
            if ($limit > 0 && $printed > $limit) {
                $tooMuch = true;
                $process?->stop(3);
            }
        };

        $command = [config('deployme.ssh.binary'), '-F', $config, '-T', $alias, $script->command()];
        $pending = Process::timeout($timeout)->input($script->render());
        $started = microtime(true);
        $process = $pending->start($command, $callback);

        try {
            if ($onTick !== null) {
                // Poll, so the caller gets a say while the script is quiet
                // (heartbeats, cancelling). Polling reads output too.
                while ($process->running()) {
                    $process->ensureNotTimedOut();
                    $onTick();
                    usleep(200_000);
                }
            }

            $result = $tooMuch ? null : $process->wait();
        } catch (ProcessTimedOutException) {
            throw new SshTimeout("No answer from {$alias} within {$timeout} s.");
        } catch (Throwable $e) {
            if (! $tooMuch) {
                // Lets go of the script. What it started on the server keeps
                // running: a deploy script's is stopped by its caller
                // (DeployContext, with deploy-kill.sh).
                $process->stop(3);

                throw $e;
            }
        }

        if ($tooMuch) {
            foreach ($onLine === null ? [] : $buffer->flush() as [$stream, $line]) {
                $onLine($line, $stream);
            }

            // The end of what it printed says why; the rest went to the live log.
            throw new SshOutputTooLarge($limit, self::tail($process->output()), self::tail($process->errorOutput()));
        }

        if ($onLine !== null) {
            foreach ($buffer->flush() as [$stream, $line]) {
                $onLine($line, $stream);
            }
        }

        $exitCode = $result->exitCode() ?? 255;
        $stderr = $result->errorOutput();

        // ssh hid why the proxy helper gave up: put its reason first.
        if ($exitCode === 255 && ! str_contains($stderr, 'deploy.me:') && ($why = $this->compiler->routeError($alias)) !== null) {
            $stderr = "deploy.me: {$why}\n{$stderr}";
        }

        return new SshResult($exitCode, $result->output(), $stderr, microtime(true) - $started);
    }

    /** The last bytes of a stream, so the exception never holds more than that. */
    private static function tail(string $output): string
    {
        return strlen($output) <= SshOutputTooLarge::TAIL_BYTES ? $output : substr($output, -SshOutputTooLarge::TAIL_BYTES);
    }
}
