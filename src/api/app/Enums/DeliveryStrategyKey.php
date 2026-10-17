<?php

namespace App\Enums;

/**
 * How a release gets onto a server. A project keeps all of them in its own
 * priority order; the first one that applies to a deploy wins.
 */
enum DeliveryStrategyKey: string
{
    case GhcrImage = 'ghcr_image';
    case PushArchive = 'push_archive';
    case PushRsync = 'push_rsync';
    case ServerGit = 'server_git';

    public function label(): string
    {
        return match ($this) {
            self::GhcrImage => 'GHCR image',
            self::PushArchive => 'Push archive',
            self::PushRsync => 'Push working tree',
            self::ServerGit => 'Server git',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::GhcrImage => 'Pulls the image CI pushed to ghcr.io for the commit, once that run is green.',
            self::PushArchive => 'Uploads a git archive of the commit. Docker projects then build on the server.',
            self::PushRsync => 'Uploads the folder as it is on disk, uncommitted changes included, minus .gitignore.',
            self::ServerGit => 'The server fetches from GitHub itself. Needs a deploy key on each server.',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::GhcrImage => 'tag',
            self::PushArchive => 'deploy',
            self::PushRsync => 'folder',
            self::ServerGit => 'branch',
        };
    }
}
