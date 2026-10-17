<?php

namespace App\Deploy\Upload;

use App\Deploy\Delivery\Source;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\StepFailed;
use App\Deploy\Pipeline\StepTimeout;
use App\Platform\Ssh\KnownHosts;
use App\Platform\Ssh\LineBuffer;
use App\Platform\Ssh\SshAlias;
use App\Platform\Ssh\SshConfigCompiler;
use App\Platform\Ssh\SshErrors;
use App\Platform\Ssh\SshResult;
use App\Platform\Sync\RsyncOutput;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Uploads a release with rsync over deploy.me's ssh config. With a previous
 * release to link against, unchanged files are hard links to it: quick, and
 * no extra room on the server.
 */
final class Uploader
{
    public function __construct(
        private readonly SshConfigCompiler $compiler,
        private readonly KnownHosts $knownHosts,
    ) {}

    /**
     * @param  list<string>  $filters  rsync filter rules, in order
     * @return array{files: int, transferred: int, bytes: int, total_bytes: int}
     *
     * @throws StepFailed
     */
    public function upload(DeployContext $context, Source $source, string $destination, ?string $linkDest, array $filters): array
    {
        $alias = $context->server->ssh_alias;
        SshAlias::assertValid($alias);

        $config = $this->compiler->compile();
        $this->knownHosts->materialize();

        $command = [
            (string) config('deployme.sync.rsync_binary', 'rsync'),
            '--archive',
            // The Mac's (or the container's) owners mean nothing on the server.
            '--no-owner',
            '--no-group',
            '--compress',
            '--protect-args',
            '--no-human-readable',
            '--stats',
            '--rsh='.config('deployme.ssh.binary', 'ssh').' -F '.$config,
        ];

        // An export stamps every file with the commit's time: compare by
        // content, and leave times out of it, so unchanged files still link to
        // the last release and changed ones get a fresh time (opcache notices).
        if ($source->exported) {
            $command[] = '--checksum';
            $command[] = '--no-times';
        }

        if ($linkDest !== null) {
            $command[] = '--link-dest='.$linkDest;
        }

        foreach ($filters as $rule) {
            $command[] = '--filter='.$rule;
        }

        $command[] = rtrim($source->path, '/').'/';
        $command[] = $alias.':'.rtrim($destination, '/').'/';

        $output = new RsyncOutput;
        $errors = [];
        $buffer = new LineBuffer;
        $read = function (array $lines) use ($context, $output, &$errors): void {
            foreach ($lines as [$stream, $line]) {
                if (trim($line) === '') {
                    continue;
                }

                $output->line($line);
                $context->log->line($line, $stream);

                if ($stream !== 'out') {
                    $errors[] = $line;
                }
            }
        };

        $process = Process::timeout(StepTimeout::cap((int) config('deployme.deploy.upload_timeout', 3000)))
            ->env(['LC_ALL' => 'C'])
            ->start($command, fn (string $type, string $chunk) => $read($buffer->push($type, $chunk)));

        try {
            while ($process->running()) {
                $process->ensureNotTimedOut();
                $context->tick();
                usleep((int) config('deployme.sync.poll_ms', 200) * 1000);
            }
        } catch (Throwable $e) {
            $process->stop(5);

            throw $e;
        }

        $exitCode = $process->wait()->exitCode() ?? 1;
        $read($buffer->flush());

        // 24: files vanished mid-copy, normal for a folder in use.
        if ($exitCode !== 0 && $exitCode !== 24) {
            if ($exitCode === 255 || ($exitCode === 12 && str_contains(implode(' ', $errors), 'connection unexpectedly closed'))) {
                throw SshErrors::from(new SshResult(255, '', implode("\n", $errors), 0));
            }

            $last = trim(implode(' ', array_slice($errors, -3)));

            throw new StepFailed('The upload failed: '.($last !== '' ? Str::limit($last, 400) : "rsync stopped with exit code {$exitCode}."), $exitCode);
        }

        $stats = $output->stats();

        return [
            'files' => (int) ($stats['files'] ?? 0),
            'transferred' => (int) $stats['transferred'],
            'bytes' => (int) $stats['transferred_bytes'],
            'total_bytes' => (int) ($stats['total_bytes'] ?? 0),
        ];
    }
}
