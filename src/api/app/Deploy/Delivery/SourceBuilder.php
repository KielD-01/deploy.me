<?php

namespace App\Deploy\Delivery;

use App\Deploy\DeployContext;
use App\Deploy\Pipeline\StepFailed;
use App\Enums\DeliveryStrategyKey;
use App\Enums\ProjectSource;
use App\Platform\Git\GitFailed;
use App\Platform\Git\GitRunner;
use App\Platform\Local\ProjectRoots;
use App\Support\Format;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Gets the files a delivery strategy ships into a folder in the worker.
 */
final class SourceBuilder
{
    public function __construct(
        private readonly GitRunner $git,
        private readonly ProjectRoots $roots,
        private readonly GitHubMirror $mirror,
    ) {}

    /**
     * @throws StepFailed
     */
    public function build(DeployContext $context, DeliveryStrategyKey $strategy, ?string $commit): Source
    {
        return match ($strategy) {
            // The images come from the registry; the compose files and configs from the commit.
            DeliveryStrategyKey::PushArchive, DeliveryStrategyKey::GhcrImage => $this->export($context, $commit),
            DeliveryStrategyKey::PushRsync => $this->folder($context),
            default => throw new StepFailed("{$strategy->label()} doesn’t upload files from here."),
        };
    }

    private function folder(DeployContext $context): Source
    {
        $path = $this->roots->resolve((string) $context->project->local_path)
            ?? throw new StepFailed(Format::homePath($context->project->local_path).' isn’t there any more.');

        $context->log->note('Uploading '.Format::homePath($path).' as it is');

        return new Source($path, exported: false, temporary: false);
    }

    private function export(DeployContext $context, ?string $commit): Source
    {
        $project = $context->project;
        // git says nothing for minutes on a big repository: the run still reports.
        $beat = fn () => $context->tick();

        if ($project->source === ProjectSource::Github) {
            $context->log->note("Fetching {$project->githubFullName()} from GitHub");
            $repository = $this->mirror->sync($project, $beat);
        } else {
            $repository = $this->roots->resolve((string) $project->local_path)
                ?? throw new StepFailed(Format::homePath($project->local_path).' isn’t there any more.');
        }

        $ref = $commit ?? $project->branch;
        $sha = $this->git->run($repository, ['rev-parse', '--verify', '--quiet', '--end-of-options', $ref.'^{commit}'], timeout: 30);

        if (! $sha->successful()) {
            throw new StepFailed($commit !== null ? "Commit {$commit} isn’t in the repository." : "The branch {$project->branch} isn’t in the repository.");
        }

        $sha = trim($sha->output());
        $message = trim($this->git->run($repository, ['log', '-1', '--format=%s', $sha], timeout: 30)->output());

        $dir = sys_get_temp_dir().'/deployme-release-'.$context->deployment->id.'-'.Str::lower(Str::random(6));
        $tar = "{$dir}.tar";

        try {
            if (! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
                throw new StepFailed("Couldn’t create {$dir} in the worker.");
            }

            $this->git->mustRun($repository, ['archive', '--format=tar', '-o', $tar, $sha], timeout: 900, while: $beat);
            $unpack = Process::timeout(900)->start(['tar', '-xf', $tar, '-C', $dir]);

            try {
                while ($unpack->running()) {
                    $unpack->ensureNotTimedOut();
                    $beat();
                    usleep(200_000);
                }
            } catch (Throwable $e) {
                $unpack->stop(5);

                throw $e;
            }

            $unpack = $unpack->wait();

            if (! $unpack->successful()) {
                throw new StepFailed('Couldn’t unpack the archive: '.Str::limit(trim($unpack->errorOutput()), 200));
            }
        } catch (GitFailed $e) {
            throw new StepFailed('Couldn’t export the commit: '.$e->getMessage());
        } finally {
            @unlink($tar);
        }

        $context->log->note('Exported '.substr($sha, 0, 7).($message !== '' ? " “{$message}”" : ''));

        return new Source($dir, exported: true, temporary: true, commit: $sha, commitMessage: $message);
    }
}
