<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\DeployContext;
use App\Deploy\Pipeline\AlwaysRuns;
use App\Deploy\Pipeline\Step;
use Illuminate\Support\Str;

/**
 * Uploads the project's env files: into shared/ for versionable projects,
 * the project folder otherwise. Their secrets are masked in the log from the
 * run's first line (DeployContext::for). A retry writes
 * them again, so a fix made since reaches the server.
 */
final class SyncEnvFiles implements AlwaysRuns, Step
{
    public function key(): string
    {
        return 'env';
    }

    public function label(): string
    {
        return 'Sync env files';
    }

    public function shouldRun(DeployContext $context): bool
    {
        return $context->project->envFiles()->exists();
    }

    public function run(DeployContext $context): ?string
    {
        $files = $context->project->envFiles()->get();

        foreach ($files as $file) {
            $context->script('deploy-write-file', [
                'FILE' => $context->layout->envFile($file->path),
                'CONTENT' => $file->content,
                'MODE' => '0600',
            ], timeout: 60)->throwIfFailed("Couldn’t write {$file->path}.");
        }

        return $files->count().' '.Str::plural('file', $files->count()).' · v'.$files->pluck('version')->implode(', v');
    }
}
