<?php

namespace App\Actions\Folders;

use App\Enums\SyncDirection;
use App\Enums\SyncRunStatus;
use App\Events\FolderSyncUpdated;
use App\Models\FolderMapping;
use App\Models\SyncRun;
use App\Platform\Local\ProjectRoots;
use App\Platform\Remote\RemoteFs;
use App\Platform\Ssh\Exceptions\SshException;
use App\Platform\Ssh\KnownHosts;
use App\Platform\Ssh\LineBuffer;
use App\Platform\Ssh\SshConfigCompiler;
use App\Platform\Ssh\SshErrors;
use App\Platform\Ssh\SshResult;
use App\Platform\Sync\RsyncCommand;
use App\Platform\Sync\RsyncOutput;
use App\Support\FolderMappingRules;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Runs rsync for one sync run, in the worker (which mounts the project
 * folders read-write), and records what changed.
 */
class RunFolderSync
{
    private const CANCEL_CHECK_EVERY = 2.0;

    public function __construct(
        private readonly SshConfigCompiler $compiler,
        private readonly KnownHosts $knownHosts,
        private readonly RemoteFs $fs,
        private readonly ProjectRoots $roots,
    ) {}

    public function __invoke(SyncRun $run): void
    {
        $mapping = $run->mapping()->with('server')->firstOrFail();
        $run->forceFill(['status' => SyncRunStatus::Running, 'started_at' => now()])->save();
        $this->announce($mapping);

        try {
            if (($problem = FolderMappingRules::problem($mapping, $this->roots)) !== null) {
                throw new RuntimeException($problem);
            }

            $config = $this->compiler->compile();
            $this->knownHosts->materialize();

            // A preview changes nothing, not even the destination folder: rsync's
            // dry run copes with one that doesn't exist yet.
            if (! $run->dry_run) {
                $this->ensureDestination($mapping);
            }

            [$output, $errors, $exitCode] = $this->rsync($run, $mapping, $config);

            $run->forceFill([
                'status' => ($message = $this->failure($run, $exitCode, $errors)) === null ? SyncRunStatus::Succeeded : SyncRunStatus::Failed,
                'stats' => $output->stats(),
                'changes' => $output->changes,
                'change_count' => $output->changeCount(),
                'error' => $message,
            ]);
        } catch (SyncCancelled) {
            $run->forceFill(['status' => SyncRunStatus::Cancelled, 'error' => 'Cancelled; what was copied so far stays.']);
        } catch (SshException $e) {
            $run->forceFill(['status' => SyncRunStatus::Failed, 'error' => $e->userMessage()]);
        } catch (Throwable $e) {
            if (! $e instanceof RuntimeException) {
                report($e);
            }
            $run->forceFill(['status' => SyncRunStatus::Failed, 'error' => $e->getMessage()]);
        }

        $run->forceFill(['finished_at' => now()])->save();
        $this->announce($mapping);
    }

    /**
     * @return array{0: RsyncOutput, 1: list<string>, 2: int}
     *
     * @throws SyncCancelled
     */
    private function rsync(SyncRun $run, FolderMapping $mapping, string $config): array
    {
        $output = new RsyncOutput;
        $errors = [];
        $buffer = new LineBuffer;
        $read = function (array $lines) use ($output, &$errors): void {
            foreach ($lines as [$stream, $line]) {
                if ($stream === 'out') {
                    $output->line($line);
                } elseif (trim($line) !== '') {
                    $errors[] = $line;
                }
            }
        };

        $process = Process::timeout((int) config('deployme.sync.timeout', 3300))
            ->env(['LC_ALL' => 'C'])
            ->start(
                RsyncCommand::for($mapping, $config, $run->dry_run, $run->max_delete),
                fn (string $type, string $chunk) => $read($buffer->push($type, $chunk)),
            );

        $checked = microtime(true);
        $pause = (int) config('deployme.sync.poll_ms', 200) * 1000;

        try {
            while ($process->running()) {
                $process->ensureNotTimedOut();

                if (microtime(true) - $checked >= self::CANCEL_CHECK_EVERY) {
                    $checked = microtime(true);

                    if (SyncRun::query()->whereKey($run->id)->whereNotNull('cancel_requested_at')->exists()) {
                        throw new SyncCancelled;
                    }
                }

                usleep($pause);
            }
        } catch (Throwable $e) {
            $process->stop(5);

            throw $e;
        }

        $result = $process->wait();
        $read($buffer->flush());

        return [$output, $errors, $result->exitCode() ?? 1];
    }

    /**
     * What went wrong, from rsync's exit code and its last words; null when all is well.
     *
     * @param  list<string>  $errors
     */
    private function failure(SyncRun $run, int $exitCode, array $errors): ?string
    {
        $last = implode(' ', array_slice($errors, -3));

        return match (true) {
            // 24: files vanished mid-copy, which is normal for folders in use.
            $exitCode === 0, $exitCode === 24 => null,
            $exitCode === 25 => 'It would have deleted more than '.(int) $run->max_delete.' '.Str::plural('file', (int) $run->max_delete).', so deleting stopped there. Preview it to see why.',
            // 12 and 255: ssh never got going.
            $exitCode === 255, $exitCode === 12 && str_contains($last, 'connection unexpectedly closed') => SshErrors::from(new SshResult(255, '', implode("\n", $errors), 0))->userMessage(),
            $last !== '' => Str::limit($last, 500),
            default => "rsync stopped with exit code {$exitCode}.",
        };
    }

    /**
     * rsync creates the last folder of the destination, not the ones above it.
     */
    private function ensureDestination(FolderMapping $mapping): void
    {
        if ($mapping->direction === SyncDirection::Push) {
            $this->fs->mkdir($mapping->server->ssh_alias, $mapping->remote_path);

            return;
        }

        $local = $this->roots->normalize($mapping->local_path);
        if (! is_dir($local) && ! @mkdir($local, 0755, true) && ! is_dir($local)) {
            throw new RuntimeException("Couldn’t create {$local}.");
        }
    }

    private function announce(FolderMapping $mapping): void
    {
        rescue(fn () => FolderSyncUpdated::dispatch($mapping->server_id, $mapping->id), report: false);
    }
}
