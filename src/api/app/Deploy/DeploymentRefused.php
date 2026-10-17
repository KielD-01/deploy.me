<?php

namespace App\Deploy;

use App\Models\Target;
use RuntimeException;

/**
 * The target can't take this run as it is: StartDeployment says why before
 * anything is queued, in words the page, the shell or an agent can show.
 */
final class DeploymentRefused extends RuntimeException
{
    /**
     * Releases are how the project deploys, but the folders still run from an
     * earlier deployment: they're converted first.
     *
     * @param  iterable<Target>  $targets
     */
    public static function unconverted(iterable $targets): self
    {
        $names = collect($targets)->map(fn (Target $target) => $target->server->name);
        $one = $names->count() === 1;

        return new self($names->implode(', ').($one ? ' still runs' : ' still run').' from an earlier deployment. Convert '.($one ? 'that folder' : 'those folders').' first: it’s on the Targets tab.');
    }
}
