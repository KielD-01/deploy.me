<?php

namespace App\Actions\Deployments;

use App\Deploy\Actions\ActionDefinition;
use App\Deploy\Actions\ActionInputs;
use App\Deploy\DeploymentBusy;
use App\Deploy\DeploymentRefused;
use App\Enums\AuditAction;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\ProjectAction;
use App\Models\Target;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Crypt;

/**
 * Starts one of a project's actions on a target, for every way one starts:
 * the run dialog, the Console shell, an MCP agent, the schedule and a deploy
 * that just ended. It's a run like a deploy (StartDeployment queues it on
 * the deploys worker), so it takes the folder's lock and waits for no one: a
 * target that's busy refuses it.
 */
class StartAction
{
    // What started it: someone on a page, the Console shell, an agent, the
    // scheduler, or a deploy that ended.
    public const TRIGGERS = ['manual', 'console', 'mcp', 'schedule', 'after_deploy'];

    public function __construct(private readonly StartDeployment $start) {}

    /**
     * @param  array<string, mixed>  $inputs  by input name, as given
     * @param  array<string, mixed>  $extra  more options: after (the deploy that ended), chain (actions to start after this one)
     * @param  bool  $identityConfirmed  the person confirmed with a passkey just now (an action that asks for one needs it)
     *
     * @throws DeploymentBusy
     * @throws DeploymentRefused
     */
    public function __invoke(ProjectAction $action, Target $target, array $inputs = [], ?User $user = null, string $trigger = 'manual', array $extra = [], bool $identityConfirmed = false): Deployment
    {
        $definition = $this->check($action, $target, $trigger, $identityConfirmed);
        $values = ActionInputs::resolve($definition, $inputs);
        $secrets = ActionInputs::secrets($definition, $values);
        $release = $target->live();

        $options = [
            'action_id' => $action->id,
            'action' => $action->key,
            'name' => $definition->name(),
            'source' => $action->source,
            'file' => $action->file,
            'commit' => $action->commit_sha,
            'release' => $release?->name,
            'definition' => $definition->data,
            // Secrets are never stored as they are: encrypted until the run
            // starts (ResolveAction drops them then), shown as a mask.
            'inputs' => array_diff_key($values, $secrets),
            'shown' => ActionInputs::display($definition, $values),
            'secrets' => $secrets !== [] ? Crypt::encryptString((string) json_encode($secrets)) : null,
            'alert' => $definition->alert(),
            ...array_intersect_key($extra, array_flip(['after', 'chain'])),
        ];

        try {
            $run = ($this->start)($target, DeploymentKind::Action, $user, array_filter($options, fn ($value) => $value !== null), $trigger, [
                'action_id' => $action->id,
                'commit_sha' => $release?->commit_sha,
                'commit_message' => $release?->commit_message !== null ? mb_strimwidth((string) $release->commit_message, 0, 250, '…') : null,
            ]);
        } catch (DeploymentBusy) {
            $busy = Deployment::query()->where('target_id', $target->id)->whereIn('status', DeploymentStatus::ACTIVE)->latest('id')->first();

            throw new DeploymentBusy(($busy !== null ? "{$busy->title()} {$busy->number()} is" : 'A run is').' already going on '.$target->onServer()->name.'. An action takes the folder’s deploy lock too: start it again once that’s done.');
        }

        Audit::record(AuditAction::ActionStarted, [
            'subject' => "{$definition->name()} on {$target->onServer()->name}",
            'detail' => self::why($trigger, $extra)." · run {$run->number()}",
            'server_id' => $target->server_id,
            'project_id' => $target->project_id,
            'user' => $user,
        ]);

        return $run;
    }

    /**
     * Whether the action can start on the target this way, as it is now. Says
     * why not, in words a page, the shell or an agent can show.
     *
     * @throws DeploymentRefused
     */
    public function check(ProjectAction $action, Target $target, string $trigger = 'manual', bool $identityConfirmed = false): ActionDefinition
    {
        $definition = $action->definition();

        if ($definition === null) {
            throw new DeploymentRefused("{$action->name} has errors, so it can’t run: ".implode(' ', $action->errors ?? []));
        }

        $target->loadMissing(['server', 'project', 'currentRelease']);

        if ($target->project_id !== $action->project_id || $target->archived_at !== null) {
            throw new DeploymentRefused("{$target->ofProject()->name} isn’t on {$target->onServer()->name}.");
        }

        if (! $definition->allows($target)) {
            throw new DeploymentRefused("{$definition->name()} doesn’t run on {$target->onServer()->name}. It runs on: ".implode(', ', $definition->targets() ?? []).'.');
        }

        $refusal = match (true) {
            in_array($trigger, ['manual', 'console', 'mcp'], true) && ! $definition->manual() => "{$definition->name()} only runs on its own (".self::when($definition).'), not when someone starts it.',
            $trigger === 'mcp' && $definition->passkey() => "{$definition->name()} asks for a passkey, so only you can start it, from its page.",
            $trigger === 'mcp' && ! $definition->mcp() => "{$definition->name()} isn’t open to agents. Turn on “Allow from MCP” for it (mcp: true in its file).",
            $trigger === 'schedule' && $definition->schedule() === null => "{$definition->name()} has no schedule.",
            $trigger === 'after_deploy' && $definition->afterDeploy() === null => "{$definition->name()} doesn’t run after deploys.",
            in_array($trigger, ['schedule', 'after_deploy'], true) && $definition->passkey() => "{$definition->name()} asks for a passkey, so it never runs on its own.",
            $definition->passkey() && ! $identityConfirmed => "{$definition->name()} asks for your passkey first.",
            ! in_array($trigger, self::TRIGGERS, true) => "“{$trigger}” isn’t a way an action starts.",
            default => null,
        };

        if ($refusal !== null) {
            throw new DeploymentRefused($refusal);
        }

        // Actions run in the live release's folder: there has to be one.
        if (! $target->isLive() || ($target->ofProject()->versionable && ! $target->live()?->inReleasesFolder())) {
            throw new DeploymentRefused("Nothing is live on {$target->onServer()->name} yet. An action runs in the live release’s folder: deploy first.");
        }

        return $definition;
    }

    /**
     * Where the steps run on the server: the live release's folder, or the
     * project's folder when it deploys in place.
     */
    public static function folder(Target $target): string
    {
        $release = $target->live();

        return $target->ofProject()->versionable && $release !== null && $release->inReleasesFolder() ? $release->path : $target->path;
    }

    /**
     * "manual", "schedule", "after deploy #20" — for the audit log and lists.
     *
     * @param  array<string, mixed>  $extra
     */
    public static function why(string $trigger, array $extra = []): string
    {
        return match ($trigger) {
            'after_deploy' => 'after deploy'.(isset($extra['after']) ? ' #'.$extra['after'] : ''),
            'mcp' => 'MCP',
            default => $trigger,
        };
    }

    /**
     * When it runs on its own: "daily 03:00", "after a deploy".
     */
    public static function when(ActionDefinition $definition): string
    {
        return implode(', ', array_filter([$definition->scheduleLabel(), $definition->afterDeployLabel()])) ?: 'never';
    }
}
