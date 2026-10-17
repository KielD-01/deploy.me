<?php

namespace App\Actions\Targets;

use App\Enums\AuditAction;
use App\Models\Project;
use App\Models\Server;
use App\Models\Target;
use App\Support\Audit;
use App\Support\TargetFolderRules;
use Illuminate\Validation\ValidationException;

/**
 * Points a target at another folder on its server, while nothing deploy.me
 * deployed runs from the old one. Only the record changes: the next deploy
 * (or Prepare) builds the layout in the new folder, and whatever Prepare made
 * in the old one stays on the server.
 *
 * A live target is refused. Its releases/, shared/ and running stack are in
 * the old folder; moving them under a running stack would leave containers
 * pointing at the old paths, and the next start would come up with empty
 * bind mounts. The refusal says how to move it by hand.
 */
class ChangeTargetFolder
{
    /**
     * @param  bool  $exact  `$folder` is where the project lives, not the folder to put it in
     *
     * @throws ValidationException on `moveFolder`
     */
    public function __invoke(Target $target, string $folder, bool $exact = false): Target
    {
        $target->loadMissing(['project', 'server', 'currentRelease']);
        /** @var Project $project */
        $project = $target->project;
        /** @var Server $server */
        $server = $target->server;
        $folder = TargetFolderRules::normalize($folder);
        $path = $exact ? $folder : Target::pathFor($folder, $project->slug);

        $problem = match (true) {
            $target->activeDeployment()->exists() => 'A run is still going on this target. Wait for it, or cancel it first.',
            $target->isLive() => self::liveProblem($target),
            $path === rtrim($target->path, '/') => "It already deploys to {$path}.",
            default => TargetFolderRules::problem($server, $path, except: $target),
        };

        if ($problem !== null) {
            throw ValidationException::withMessages(['moveFolder' => $problem]);
        }

        $from = $target->path;

        $target->forceFill([
            'base_dir' => dirname($path),
            'path' => $path,
            // The new folder hasn't been prepared or read.
            'bootstrapped_at' => null,
            'folder_scan' => null,
            'folder_scanned_at' => null,
        ])->save();

        Audit::record(AuditAction::TargetFolderChanged, [
            'subject' => "{$project->name} on {$server->name} · {$from} → {$path}",
            'server_id' => $target->server_id,
            'project_id' => $target->project_id,
            'detail' => 'nothing on the server changed',
        ]);

        return $target;
    }

    public static function liveProblem(Target $target): string
    {
        /** @var Project $project */
        $project = $target->project;

        return "{$project->name} is live in {$target->path}, so deploy.me won’t move it: its releases, shared files and stack run from there. "
            .'To move it, remove the target (nothing on the server changes), assign the project again with the new folder, copy shared/ across on the server, deploy, and delete the old folder by hand once it’s healthy.';
    }
}
