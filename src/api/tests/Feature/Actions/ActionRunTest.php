<?php

use App\Actions\Deployments\DispatchActionsAfter;
use App\Actions\Deployments\DispatchScheduledActions;
use App\Actions\Deployments\StartAction;
use App\Deploy\DeploymentBusy;
use App\Deploy\DeploymentRefused;
use App\Deploy\Pipeline\PipelineRunner;
use App\Enums\AuditAction;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Events\DeploymentLogged;
use App\Events\DeploymentUpdated;
use App\Jobs\RunDeployment;
use App\Models\AuditEntry;
use App\Models\Deployment;
use App\Models\ProjectEnvFile;
use App\Models\User;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshResult;
use App\Platform\Ssh\Testing\FakeSshClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

const ACTION_YAML = <<<'YAML'
    name: Reindex search
    inputs:
      index: { type: choice, options: [products, orders, all], default: products }
      fresh: { type: boolean, default: false }
      token: secret
    steps:
      - name: Flush the index
        if: inputs.fresh
        exec: api
        run: php artisan scout:flush "$INDEX"
      - name: Import
        one-off: worker
        run: php artisan scout:import "$INDEX"
      - name: Ping
        run: curl -fsS "https://example.test/?t=$TOKEN"
    YAML;

beforeEach(function () {
    $this->ssh = fakeDeployScripts(new FakeSshClient);
    $this->app->instance(SshClient::class, $this->ssh);
    Event::fake([DeploymentUpdated::class, DeploymentLogged::class]);
    $this->user = User::factory()->create();
});

/**
 * The variables a step's script got over stdin, decoded: NAME => value.
 *
 * @return array<string, string>
 */
function stepEnv(RemoteScript $script): array
{
    $env = [];
    foreach (array_filter(explode("\n", (string) scriptVar($script, 'ENV_VARS'))) as $line) {
        [$name, $value] = explode('=', $line, 2);
        $env[$name] = base64_decode($value);
    }

    return $env;
}

/**
 * The scripts the action's steps ran (deploy-hook and deploy-compose), in order.
 *
 * @return list<RemoteScript>
 */
function stepScripts(FakeSshClient $ssh): array
{
    return collect($ssh->calls)->pluck('script')->filter(fn (RemoteScript $script) => in_array($script->name, ['deploy-hook', 'deploy-compose'], true))->values()->all();
}

/**
 * @return array<string, string> step key => status
 */
function actionStepMap(Deployment $run): array
{
    return $run->steps()->get()->mapWithKeys(fn ($step) => [$step->key => $step->status->value])->all();
}

function startAndRun(...$arguments): Deployment
{
    Queue::fake();
    $run = app(StartAction::class)(...$arguments);
    app(PipelineRunner::class)->run($run);

    return $run->refresh();
}

it('runs each step where it says, in the live release, with the inputs as env over stdin', function () {
    $target = actionTarget();
    $action = projectAction($target->project, ACTION_YAML);

    $run = startAndRun($action, $target, ['index' => 'all', 'fresh' => 'yes', 'token' => 's3cr3t-value'], $this->user);
    $scripts = stepScripts($this->ssh);

    expect($run->status)->toBe(DeploymentStatus::Succeeded)
        ->and($run->kind)->toBe(DeploymentKind::Action)
        ->and($run->action_id)->toBe($action->id)
        ->and($run->steps()->pluck('key')->all())->toBe(['lease', 'action', 'action_step_1', 'action_step_2', 'action_step_3', 'unlock'])
        ->and($run->outcome)->toBe('Reindex search ran on '.$target->server->name.': 3 steps ran.')
        ->and($scripts)->toHaveCount(3)
        ->and(scriptVar($scripts[0], 'ACTION'))->toBe('exec')
        ->and(scriptVar($scripts[0], 'SERVICES'))->toBe('api')
        ->and(scriptVar($scripts[0], 'DIR'))->toBe('/opt/srv/demo/releases/20261017-161400')
        ->and(scriptVar($scripts[1], 'ACTION'))->toBe('run')
        ->and(scriptVar($scripts[1], 'SERVICES'))->toBe('worker')
        ->and($scripts[2]->name)->toBe('deploy-hook')
        ->and(scriptVar($scripts[2], 'COMMAND'))->toBe('curl -fsS "https://example.test/?t=$TOKEN"')
        ->and(stepEnv($scripts[0]))->toBe(['INDEX' => 'all', 'FRESH' => '1', 'TOKEN' => 's3cr3t-value']);

    // Values reach the server inside the script on stdin, never in ssh's command line.
    foreach ($scripts as $script) {
        expect($script->command())->not->toContain('s3cr3t-value')->not->toContain('all');
    }
});

it('skips a step whose if input is off', function () {
    $target = actionTarget();
    $run = startAndRun(projectAction($target->project, ACTION_YAML), $target, ['token' => 'whatever-it-is'], $this->user);

    expect(actionStepMap($run))->toMatchArray(['action_step_1' => 'skipped', 'action_step_2' => 'succeeded', 'action_step_3' => 'succeeded'])
        ->and(stepScripts($this->ssh))->toHaveCount(2)
        ->and($run->outcome)->toContain('2 steps ran, 1 skipped');
});

it('masks a secret input in the log and keeps it nowhere once the run starts', function () {
    $target = actionTarget();
    $this->ssh->on('box', 'deploy-hook', FakeSshClient::ok("calling with s3cr3t-value\n"));

    $queued = (function () use ($target) {
        Queue::fake();

        return app(StartAction::class)(projectAction($target->project, ACTION_YAML), $target, ['token' => 's3cr3t-value'], $this->user);
    })();

    expect(json_encode($queued->options))->not->toContain('s3cr3t-value')
        ->and($queued->options['shown']['token'])->toBe('••••••')
        ->and($queued->options['secrets'])->toBeString();

    app(PipelineRunner::class)->run($queued);
    $log = $queued->logLines()->pluck('line')->implode("\n");

    expect($log)->toContain('calling with ••••••')->not->toContain('s3cr3t-value')
        ->and($queued->refresh()->options)->not->toHaveKey('secrets')
        ->and(json_encode($queued->options))->not->toContain('s3cr3t-value');
});

it('stops at a failing step unless the step may fail', function () {
    $target = actionTarget();
    $this->ssh->on('box', 'deploy-compose', fn (string $alias, RemoteScript $script) => scriptVar($script, 'ACTION') === 'run'
        ? new SshResult(1, "SQLSTATE[42703]\n", '', 0.1)
        : FakeSshClient::ok("done\n"));

    $stopped = startAndRun(projectAction($target->project, ACTION_YAML), $target, ['token' => 'whatever-it-is'], $this->user);

    expect($stopped->status)->toBe(DeploymentStatus::Failed)
        ->and(actionStepMap($stopped))->toMatchArray(['action_step_2' => 'failed', 'action_step_3' => 'skipped', 'unlock' => 'succeeded'])
        ->and($stopped->outcome)->toContain('Import failed with exit code 1');

    $lenient = str_replace("    run: php artisan scout:import \"\$INDEX\"\n", "    run: php artisan scout:import \"\$INDEX\"\n    continue-on-error: true\n", ACTION_YAML);
    $carried = startAndRun(projectAction($target->project, $lenient, 'lenient'), $target, ['token' => 'whatever-it-is'], $this->user);

    expect($carried->status)->toBe(DeploymentStatus::Succeeded)
        ->and(actionStepMap($carried))->toMatchArray(['action_step_2' => 'failed', 'action_step_3' => 'succeeded'])
        ->and($carried->steps()->where('key', 'action_step_2')->value('exit_code'))->toBe(1)
        ->and($carried->outcome)->toContain('1 failed and was carried past');
});

it('passes the env file’s variables to a step that asks for them', function () {
    $target = actionTarget();
    $target->project->envFiles()->create(['path' => '.env', 'role' => 'compose', ...ProjectEnvFile::contentAttributes("DB_USERNAME=app\nDB_PASSWORD=very-secret-pw\n")]);
    $action = projectAction($target->project, "name: Dump\nsteps:\n  - { exec: postgres, run: 'pg_dump -U \"\$DB_USERNAME\"', env-file: true }\n  - { run: ls }\n");

    startAndRun($action, $target, [], $this->user);
    $scripts = stepScripts($this->ssh);

    expect(stepEnv($scripts[0]))->toBe(['DB_USERNAME' => 'app', 'DB_PASSWORD' => 'very-secret-pw'])
        ->and(stepEnv($scripts[1]))->toBe([]);
});

it('refuses to start while the target runs something else', function () {
    Queue::fake();
    $target = actionTarget();
    $deploy = Deployment::factory()->create(['target_id' => $target->id, 'kind' => DeploymentKind::Deploy, 'status' => DeploymentStatus::Running]);

    expect(fn () => app(StartAction::class)(projectAction($target->project, ACTION_YAML), $target, ['token' => 'x'], $this->user))
        ->toThrow(DeploymentBusy::class, "Deploy #{$deploy->id} is already going on {$target->server->name}. An action takes the folder’s deploy lock too");
});

it('refuses what the definition doesn’t allow', function (string $yaml, string $trigger, string $message) {
    Queue::fake();
    $target = actionTarget();

    expect(fn () => app(StartAction::class)(projectAction($target->project, $yaml), $target, [], $this->user, $trigger))
        ->toThrow(DeploymentRefused::class, $message);
})->with([
    'a passkey, unconfirmed' => ["name: Flush\nconfirm: passkey\nsteps: [ls]\n", 'manual', 'asks for your passkey first'],
    'a passkey, over MCP' => ["name: Flush\nconfirm: passkey\nsteps: [ls]\n", 'mcp', 'asks for a passkey, so only you can start it'],
    'not open to agents' => ["name: Flush\nsteps: [ls]\n", 'mcp', 'isn’t open to agents'],
    'only after deploys' => ["name: Smoke\non: { after_deploy: succeeded }\nsteps: [ls]\n", 'manual', 'only runs on its own (after a deploy succeeds)'],
    'another server only' => ["name: Flush\ntargets: [elsewhere]\nsteps: [ls]\n", 'manual', 'doesn’t run on'],
    'an input without a value' => ["name: Flush\ninputs: { days: number }\nsteps: [ls]\n", 'manual', 'days: give a value.'],
]);

it('runs an action that asks for a passkey once you confirmed', function () {
    Queue::fake();
    $target = actionTarget();
    $run = app(StartAction::class)(projectAction($target->project, "name: Flush\nconfirm: passkey\nsteps: [ls]\n"), $target, [], $this->user, identityConfirmed: true);

    expect($run->status)->toBe(DeploymentStatus::Queued);
    Queue::assertPushed(RunDeployment::class);
});

it('needs a live release to run in', function () {
    Queue::fake();
    $target = demoTarget(['dockerized' => true]);

    expect(fn () => app(StartAction::class)(projectAction($target->project, "name: X\nsteps: [ls]\n"), $target, [], $this->user))
        ->toThrow(DeploymentRefused::class, 'Nothing is live on');
});

it('records who started an action in the audit log, never its inputs', function () {
    Queue::fake();
    $target = actionTarget();
    app(StartAction::class)(projectAction($target->project, ACTION_YAML), $target, ['index' => 'orders', 'token' => 'audit-secret'], $this->user);

    $entry = AuditEntry::query()->where('action', AuditAction::ActionStarted->value)->sole();

    expect($entry->subject)->toBe('Reindex search on '.$target->server->name)
        ->and($entry->detail)->toStartWith('manual · run #')
        ->and($entry->project_id)->toBe($target->project_id)
        ->and($entry->detail.$entry->subject)->not->toContain('orders')->not->toContain('audit-secret');
});

it('starts scheduled actions when they come due, and again a minute later when the target was busy', function () {
    Queue::fake();
    config(['deployme.actions.timezone' => 'UTC']);
    $this->travelTo(now()->utc()->setTime(2, 58));
    $target = actionTarget();
    $action = projectAction($target->project, "name: Nightly\non: { schedule: \"0 3 * * *\" }\nsteps: [ls]\n", 'nightly');
    $dispatch = app(DispatchScheduledActions::class);

    expect($dispatch())->toBe(0);

    $this->travelTo(now()->utc()->setTime(3, 0, 20));
    $deploy = Deployment::factory()->create(['target_id' => $target->id, 'kind' => DeploymentKind::Deploy, 'status' => DeploymentStatus::Running]);

    expect($dispatch())->toBe(0);

    $deploy->update(['status' => DeploymentStatus::Succeeded]);
    $this->travelTo(now()->utc()->setTime(3, 1, 20));

    expect($dispatch())->toBe(1)
        ->and(Deployment::query()->where('action_id', $action->id)->sole()->trigger)->toBe('schedule')
        ->and($dispatch())->toBe(0);
});

it('starts the actions that follow a deploy once it has ended, one after the other', function () {
    $target = actionTarget();
    $smoke = projectAction($target->project, "name: Smoke test\non: { after_deploy: succeeded }\nsteps: [ls]\n", 'smoke');
    $workers = projectAction($target->project, "name: Restart workers\non: { manual: true, after_deploy: either }\nsteps: [ls]\n", 'workers');
    projectAction($target->project, "name: On failure\non: { after_deploy: failed }\nsteps: [ls]\n", 'on-failure');
    $deploy = Deployment::factory()->create(['target_id' => $target->id, 'kind' => DeploymentKind::Deploy, 'status' => DeploymentStatus::Succeeded, 'user_id' => $this->user->id]);

    Queue::fake();
    app(DispatchActionsAfter::class)($deploy);
    $first = Deployment::query()->where('kind', DeploymentKind::Action)->sole();

    // One run a target at a time: the second starts when the first ends.
    expect($first->action_id)->toBe($smoke->id)->and($first->options['chain'])->toBe([$workers->id]);

    app(PipelineRunner::class)->run($first);
    $second = Deployment::query()->where('kind', DeploymentKind::Action)->whereKeyNot($first->id)->sole();
    app(PipelineRunner::class)->run($second);
    $runs = Deployment::query()->where('kind', DeploymentKind::Action)->orderBy('id')->get();

    expect($runs->pluck('action_id')->all())->toBe([$smoke->id, $workers->id])
        ->and($runs->pluck('trigger')->unique()->all())->toBe(['after_deploy'])
        ->and($runs->pluck('status')->unique()->all())->toBe([DeploymentStatus::Succeeded])
        ->and($runs[0]->options['after'])->toBe($deploy->id)
        ->and($runs[1]->options['after'])->toBe($deploy->id)
        ->and($runs[0]->user_id)->toBe($this->user->id);
});

it('starts them from the runner when a deploy ends, and not after one that was cancelled', function () {
    Queue::fake();
    $target = actionTarget();
    projectAction($target->project, "name: Smoke test\non: { after_deploy: either }\nsteps: [ls]\n", 'smoke');

    $cancelled = Deployment::factory()->create(['target_id' => $target->id, 'kind' => DeploymentKind::Deploy, 'status' => DeploymentStatus::Cancelled]);
    app(DispatchActionsAfter::class)($cancelled);

    expect(Deployment::query()->where('kind', DeploymentKind::Action)->count())->toBe(0);

    $failed = Deployment::factory()->create(['target_id' => $target->id, 'kind' => DeploymentKind::Deploy, 'status' => DeploymentStatus::RolledBack]);
    app(DispatchActionsAfter::class)($failed);

    expect(Deployment::query()->where('kind', DeploymentKind::Action)->sole()->options['after'])->toBe($failed->id);
});
