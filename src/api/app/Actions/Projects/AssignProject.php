<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\Server;
use App\Models\Target;
use Illuminate\Validation\ValidationException;

/**
 * Assigns a project to a server: it will deploy to {base folder}/{slug}.
 * Nothing happens on the server until the target is prepared or deployed.
 */
class AssignProject
{
    /**
     * @throws ValidationException on `baseDir`
     */
    public function __invoke(Project $project, Server $server, string $baseDir): Target
    {
        $baseDir = rtrim(trim($baseDir), '/') ?: '/';
        $path = Target::pathFor($baseDir, $project->slug);

        $problem = match (true) {
            ! str_starts_with($baseDir, '/') || preg_match('/[\x00-\x1F\x7F]/', $baseDir) === 1 || in_array('..', explode('/', $baseDir), true) => 'Use an absolute folder path, like /opt/srv.',
            strlen($path) > 255 => 'That path is too long.',
            $server->isReserved($path) => "{$path} is inside the shared infrastructure’s folder.",
            Target::query()->active()->where('project_id', $project->id)->where('server_id', $server->id)->exists() => "{$project->name} is already on {$server->name}.",
            Target::query()->active()->where('server_id', $server->id)->where('path', $path)->exists() => "Another project already deploys to {$path}.",
            default => null,
        };

        if ($problem !== null) {
            throw ValidationException::withMessages(['baseDir' => $problem]);
        }

        return Target::query()->create([
            'project_id' => $project->id,
            'server_id' => $server->id,
            'base_dir' => $baseDir,
            'path' => $path,
        ]);
    }
}
