<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\Delivery\SourceBuilder;
use App\Deploy\Delivery\Strategies\ServerGit;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Deploy\Upload\Uploader;
use App\Enums\DeliveryStrategyKey;
use App\Models\Release;
use App\Support\Format;
use Illuminate\Support\Str;

/**
 * Gets the files onto the server: into the new release folder, linking
 * unchanged files to the live release, or straight into the project folder
 * for in-place projects. Env files never travel this way.
 */
final class UploadRelease implements Step
{
    public function key(): string
    {
        return 'upload';
    }

    public function label(): string
    {
        return 'Upload files';
    }

    public function shouldRun(DeployContext $context): bool
    {
        return ($context->memo['release'] ?? null) instanceof Release;
    }

    public function run(DeployContext $context): ?string
    {
        /** @var Release $release */
        $release = $context->memo['release'];
        /** @var DeliveryStrategyKey $strategy */
        $strategy = $context->memo['strategy'];

        if ($strategy === DeliveryStrategyKey::ServerGit) {
            return $this->fetchOnServer($context, $release);
        }

        $source = app(SourceBuilder::class)->build($context, $strategy, $strategy === DeliveryStrategyKey::PushRsync ? null : $release->commit_sha);
        $context->onFinish(fn () => $source->discard());

        // A GitHub project learns its commit once the mirror is fetched.
        if ($source->commit !== null && $source->commit !== $release->commit_sha) {
            $message = $source->commitMessage !== null ? Str::limit($source->commitMessage, 250) : null;
            $release->update(['commit_sha' => $source->commit, 'commit_message' => $message]);
            $context->deployment->forceFill(['commit_sha' => $source->commit, 'commit_message' => $message])->save();
        }

        $previous = $context->memo['previous'] ?? null;
        $inPlace = ! $context->layout->versionable;

        // In place, the files land in a folder of their own first, linked to the
        // live ones where unchanged, so a cancel or a lost connection mid-upload
        // leaves the project folder as it was.
        $destination = $inPlace ? $context->layout->incoming($release->name) : $release->path;
        $linkDest = match (true) {
            $inPlace => $context->layout->root,
            $previous instanceof Release && $previous->canSwitchTo() => $previous->path,
            default => null,
        };

        $stats = app(Uploader::class)->upload($context, $source, $destination, $linkDest, $this->filters($context, $strategy));
        $release->update(['size_bytes' => $stats['total_bytes'] ?: null]);
        $sent = $stats['transferred'].' '.Str::plural('file', $stats['transferred']).' sent · '.Format::bytes($stats['bytes']);

        if ($inPlace) {
            return $sent.' · '.$this->intoPlace($context, $release, $strategy);
        }

        return $sent.($linkDest !== null ? ' · the rest unchanged' : '');
    }

    /**
     * In place: the staged release goes into the project folder in one local
     * rsync on the server, which also removes what the release no longer has.
     * deploy.me's own files, the env files and the shared folders stay, and so
     * does what the project's .gitignore files name, unless the release brings
     * its own.
     */
    private function intoPlace(DeployContext $context, Release $release, DeliveryStrategyKey $strategy): string
    {
        // From here on the project folder changes: it's the switch.
        $context->memo['live'] = true;

        $run = $context->script('deploy-sync-in-place', [
            'TARGET' => $context->layout->root,
            'RELEASE' => $release->name,
            'FILTERS' => implode("\n", [...$this->filters($context, $strategy), 'dir-merge,-r .gitignore']),
        ], timeout: 900)->throwIfFailed('Couldn’t put the release into place.');

        $updated = (int) $run->marker('updated');
        $deleted = (int) $run->marker('deleted');

        return $updated.' '.Str::plural('file', $updated).' updated in place'.($deleted > 0 ? " · {$deleted} removed" : '');
    }

    /**
     * Server git: the server fetches the commit into a mirror of its own and
     * unpacks it where the release goes.
     */
    private function fetchOnServer(DeployContext $context, Release $release): string
    {
        $inPlace = ! $context->layout->versionable;

        $run = $context->script('deploy-server-git', [
            'REMOTE' => (string) ServerGit::remote($context->project),
            'MIRROR' => $context->layout->deployDir().'/repo.git',
            'REF' => $release->commit_sha ?? $context->project->branch,
            'DEST' => $inPlace ? $context->layout->incoming($release->name) : $release->path,
        ], timeout: 1800)->throwIfFailed('The server couldn’t fetch the commit.');

        $sha = (string) $run->marker('commit');
        if (preg_match('/^[0-9a-f]{40}$/', $sha) === 1 && $sha !== $release->commit_sha) {
            $message = Str::limit((string) $run->marker('subject'), 250);
            $release->update(['commit_sha' => $sha, 'commit_message' => $message]);
            $context->deployment->forceFill(['commit_sha' => $sha, 'commit_message' => $message])->save();
        }

        $fetched = 'fetched on the server'.($release->shortSha() !== null ? ' · '.$release->shortSha() : '');

        return $inPlace ? $fetched.' · '.$this->intoPlace($context, $release, DeliveryStrategyKey::ServerGit) : $fetched;
    }

    /**
     * @return list<string>
     */
    private function filters(DeployContext $context, DeliveryStrategyKey $strategy): array
    {
        // Env files belong to deploy.me, never to the upload.
        $filters = ['- .git', '- /.deploy/', '+ .env.example', '- .env', '- .env.*', '- .DS_Store'];

        foreach ($context->project->envFiles()->pluck('path') as $path) {
            $filters[] = '- /'.ltrim((string) $path, '/');
        }

        // In place, the server's own copies of the shared folders stay put.
        if (! $context->layout->versionable) {
            foreach ($context->project->sharedPaths() as $path) {
                $filters[] = "- /{$path}/";
            }
        }

        if ($strategy === DeliveryStrategyKey::PushRsync) {
            $filters[] = ':- .gitignore';
        }

        return $filters;
    }
}
