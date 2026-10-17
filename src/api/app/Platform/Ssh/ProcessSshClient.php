<?php

namespace App\Platform\Ssh;

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
        $callback = $onLine === null ? null : function (string $type, string $chunk) use ($buffer, $onLine): void {
            foreach ($buffer->push($type, $chunk) as [$stream, $line]) {
                $onLine($line, $stream);
            }
        };

        $command = [config('deployme.ssh.binary'), '-F', $config, '-T', $alias, $script->command()];
        $pending = Process::timeout($timeout)->input($script->render());
        $started = microtime(true);

        try {
            if ($onTick === null) {
                $result = $pending->run($command, $callback);
            } else {
                // Poll, so the caller gets a say while the script is quiet
                // (heartbeats, cancelling). Polling reads output too.
                $process = $pending->start($command, $callback);

                try {
                    while ($process->running()) {
                        $process->ensureNotTimedOut();
                        $onTick();
                        usleep(200_000);
                    }
                } catch (Throwable $e) {
                    // Lets go of the script. What it started on the server
                    // keeps running: a deploy script's is stopped by its
                    // caller (DeployContext, with deploy-kill.sh).
                    $process->stop(3);

                    throw $e;
                }

                $result = $process->wait();
            }
        } catch (ProcessTimedOutException) {
            throw new SshTimeout("No answer from {$alias} within {$timeout} s.");
        }

        if ($onLine !== null) {
            foreach ($buffer->flush() as [$stream, $line]) {
                $onLine($line, $stream);
            }
        }

        return new SshResult($result->exitCode() ?? 255, $result->output(), $result->errorOutput(), microtime(true) - $started);
    }
}
