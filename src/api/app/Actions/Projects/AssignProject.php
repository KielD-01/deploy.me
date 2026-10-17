<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\Server;
use App\Models\Target;
use App\Support\TargetFolderRules;
use Illuminate\Validation\ValidationException;

/**
 * Assigns a project to a server: it will deploy to {folder}/{slug}, or, with
 * an exact folder, to that folder whatever its name. Nothing happens on the
 * server until the target is prepared or deployed.
 */
class AssignProject
{
    /**
     * @param  bool  $exact  `$folder` is where the project lives, not the folder to put it in
     *
     * @throws ValidationException on `baseDir`, or on `folder` for an exact folder
     */
    public function __invoke(Project $project, Server $server, string $folder, bool $exact = false): Target
    {
        $folder = TargetFolderRules::normalize($folder);
        $path = $exact ? $folder : Target::pathFor($folder, $project->slug);
        $field = $exact ? 'folder' : 'baseDir';

        $problem = match (true) {
            ! str_starts_with($folder, '/') || preg_match('/[\x00-\x1F\x7F]/', $folder) === 1 || in_array('..', explode('/', $folder), true) => 'Use an absolute folder path, like /opt/srv.',
            Target::query()->active()->where('project_id', $project->id)->where('server_id', $server->id)->exists() => "{$project->name} is already on {$server->name}.",
            default => TargetFolderRules::problem($server, $path),
        };

        if ($problem !== null) {
            throw ValidationException::withMessages([$field => $problem]);
        }

        return Target::query()->create([
            'project_id' => $project->id,
            'server_id' => $server->id,
            'base_dir' => dirname($path),
            'path' => $path,
        ]);
    }
}
