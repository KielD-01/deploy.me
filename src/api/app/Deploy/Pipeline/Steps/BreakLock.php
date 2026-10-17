<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Enums\AuditAction;
use App\Support\Audit;

/**
 * Breaking a lock by hand: removes the target's deploy lock whoever holds
 * it, and says whose it was. For a lock nothing will give back, once you
 * know no deploy runs there. It's a run of its own, so who did it and when
 * stays on record.
 */
final class BreakLock implements Step
{
    public function key(): string
    {
        return 'break_lock';
    }

    public function label(): string
    {
        return 'Break the deploy lock';
    }

    public function shouldRun(DeployContext $context): bool
    {
        return true;
    }

    public function run(DeployContext $context): ?string
    {
        $run = $context->script('deploy-unlock', [
            'TARGET' => $context->layout->root,
            'OWNER' => $context->deployment->id,
            'FORCE' => 1,
        ], timeout: 60)->throwIfFailed('Couldn’t remove the lock.');

        $broken = $run->marker('broken') === '1';
        $holder = (string) $run->marker('previous');
        $context->memo['broken'] = ['broken' => $broken, 'holder' => $holder];

        $said = match (true) {
            ! $broken => 'there was no lock',
            $holder !== '' => "deploy #{$holder} held it",
            default => 'it named no deploy',
        };

        Audit::record(AuditAction::LockBroken, [
            'subject' => $context->layout->root.' on '.$context->server->name,
            'server_id' => $context->server->id,
            'project_id' => $context->project->id,
            'detail' => $said,
            'user' => $context->deployment->user,
        ]);

        return $said;
    }
}
