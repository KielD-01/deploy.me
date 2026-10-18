<?php

use App\Actions\Deployments\StartAction;
use App\Enums\AuditAction;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Jobs\RunDeployment;
use App\Livewire\Deployments\Show as RunPage;
use App\Livewire\Projects\ActionEditor;
use App\Livewire\Projects\Actions;
use App\Livewire\Projects\RunActionDialog;
use App\Models\AuditEntry;
use App\Models\Deployment;
use App\Models\ProjectAction;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

const PAGE_ACTION = <<<'YAML'
    name: Reindex search
    on: { manual: true, schedule: "30 4 * * 0" }
    inputs:
      index: { type: choice, options: [products, orders], default: products }
      fresh: { type: boolean, description: Flush the index first }
    steps:
      - { name: Flush the index, if: inputs.fresh, exec: api, run: php artisan scout:flush }
      - { name: Import, one-off: worker, run: php artisan scout:import, timeout: 15m }
    YAML;

beforeEach(function () {
    Queue::fake();
    $this->withoutVite();
    $this->actingAs(User::factory()->create());
});

it('shows the Actions tab with its count, in both themes', function (string $theme) {
    auth()->user()->forceFill(['ui' => ['theme' => $theme]])->save();
    $target = actionTarget();
    projectAction($target->project, PAGE_ACTION, 'reindex-search', ProjectAction::REPO);
    $target->project->actions()->create(['source' => ProjectAction::REPO, 'key' => 'broken', 'file' => '.deployme/actions/broken.yml', 'name' => 'broken', 'errors' => ['steps: a list of at least one step.']]);

    $this->get(route('projects.show', ['project' => $target->project, 'tab' => 'actions']))
        ->assertOk()
        ->assertSee('Actions')
        ->assertSee($theme === 'console' ? 'reindex-search' : 'Reindex search')
        ->assertSee($theme === 'console' ? '.deployme/actions/broken.yml' : '.deployme/actions/reindex-search.yml')
        ->assertSee('steps: a list of at least one step.')
        ->assertSee($theme === 'console' ? 'reload from repo' : 'Reload from repo');
})->with(['classic', 'console']);

it('saves an action made here, after a passkey', function () {
    $target = actionTarget();

    // No recent passkey: the editor asks for one before it opens.
    Livewire::test(ActionEditor::class, ['project' => $target->project])->assertRedirect(route('password.confirm'));

    session(['auth.password_confirmed_at' => time()]);

    Livewire::test(ActionEditor::class, ['project' => $target->project])
        ->set('name', 'Database snapshot')
        ->set('steps.0.name', 'Dump the database')
        ->set('steps.0.run_in', 'service_exec')
        ->set('steps.0.service', 'postgres')
        ->set('steps.0.run', 'pg_dump -Fc "$DB_DATABASE" > /backups/x.dump')
        ->set('steps.0.timeout', '10m')
        ->set('steps.0.env_file', true)
        ->call('addInput')
        ->set('inputs.0.name', 'KEEP_DAYS')
        ->set('inputs.0.type', 'number')
        ->set('inputs.0.default', '14')
        ->set('scheduled', true)
        ->set('schedule', '0 3 * * *')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('problems', [])
        ->assertRedirect(route('projects.show', ['project' => $target->project, 'tab' => 'actions']));

    $action = ProjectAction::query()->sole();
    $definition = $action->definition();

    expect($action)->key->toBe('database-snapshot')->source->toBe(ProjectAction::APP)
        ->and($definition->schedule())->toBe('0 3 * * *')
        ->and($definition->scheduledTarget($target->project)->id)->toBe($target->id)
        ->and($definition->targets())->toBeNull()
        ->and($definition->steps()[0])->toMatchArray(['run_in' => 'service_exec', 'service' => 'postgres', 'timeout' => 600, 'env_file' => true])
        ->and($definition->input('KEEP_DAYS'))->toMatchArray(['type' => 'number', 'default' => 14])
        ->and(AuditEntry::query()->where('action', AuditAction::ActionCreated->value)->sole()->detail)->toBe('1 step · manual, daily 03:00');
});

it('shows what doesn’t save and keeps the form', function () {
    session(['auth.password_confirmed_at' => time()]);
    $target = actionTarget();

    Livewire::test(ActionEditor::class, ['project' => $target->project])
        ->set('name', 'Half done')
        ->set('passkey', true)
        ->set('afterDeploy', true)
        ->call('save')
        ->assertSet('problems', fn (array $problems) => str_contains(implode(' ', $problems), 'steps.1.run: the command to run')
            && str_contains(implode(' ', $problems), 'on.after_deploy: an action that asks for a passkey'))
        ->assertNoRedirect();

    expect(ProjectAction::query()->count())->toBe(0);
});

it('edits an action made here, and shows a repo one read-only', function () {
    session(['auth.password_confirmed_at' => time()]);
    $target = actionTarget();
    $mine = projectAction($target->project, PAGE_ACTION, 'reindex-search');
    $repo = projectAction($target->project, PAGE_ACTION, 'from-git', ProjectAction::REPO);

    Livewire::test(ActionEditor::class, ['project' => $target->project, 'action' => $mine])
        ->assertSet('name', 'Reindex search')
        ->assertSet('steps.1.timeout', '15m')
        ->assertSet('inputs.0.options', 'products, orders')
        ->set('steps.1.timeout', '20m')
        ->call('save');

    expect($mine->refresh()->definition()->steps()[1]['timeout'])->toBe(1200)
        ->and(AuditEntry::query()->where('action', AuditAction::ActionUpdated->value)->count())->toBe(1);

    Livewire::test(ActionEditor::class, ['project' => $target->project, 'action' => $repo])
        ->assertSee('From the repo')
        ->assertSee('scout:import')
        ->assertDontSee('Copy as YAML')
        ->call('save');

    expect($repo->refresh()->definition()->steps()[1]['timeout'])->toBe(900);
});

it('copies an action as the YAML of a repo file', function () {
    session(['auth.password_confirmed_at' => time()]);
    $target = actionTarget();
    $mine = projectAction($target->project, PAGE_ACTION, 'reindex-search');

    Livewire::test(ActionEditor::class, ['project' => $target->project, 'action' => $mine])
        ->call('showYaml')
        ->assertSee('.deployme/actions/reindex-search.yml')
        ->assertSee('one-off: worker');
});

it('removes an action made here, never one from the repo', function () {
    $target = actionTarget();
    $mine = projectAction($target->project, PAGE_ACTION, 'mine');
    $repo = projectAction($target->project, PAGE_ACTION, 'theirs', ProjectAction::REPO);

    Livewire::test(Actions::class, ['projectId' => $target->project_id])
        ->call('delete', $repo->id)
        ->assertSet('problem', fn (string $problem) => str_contains($problem, 'comes from the repository'))
        ->call('delete', $mine->id)
        ->assertSet('notice', 'Removed Reindex search.');

    expect(ProjectAction::query()->pluck('key')->all())->toBe(['theirs'])
        ->and(AuditEntry::query()->where('action', AuditAction::ActionRemoved->value)->count())->toBe(1);
});

it('runs an action from its dialog: target, inputs, what will run', function () {
    $target = actionTarget();
    $action = projectAction($target->project, PAGE_ACTION, 'reindex-search');

    $dialog = Livewire::test(RunActionDialog::class, ['projectId' => $target->project_id])
        ->dispatch('open-action-dialog', actionId: $action->id)
        ->assertSet('open', true)
        ->assertSet('targetId', $target->id)
        ->assertSet('values', ['index' => 'products', 'fresh' => false])
        ->assertSee('What will run')
        ->assertSee('exec api')
        ->assertSee('one-off worker')
        ->assertSee('skipped: fresh is off')
        ->assertSee('/opt/srv/demo/releases/20261017-161400')
        ->call('pick', 'index', 'orders')
        ->set('values.fresh', true)
        ->call('run');

    $run = Deployment::query()->where('kind', DeploymentKind::Action)->sole();
    $dialog->assertRedirect(route('deployments.show', $run));

    expect($run->options['inputs'])->toBe(['index' => 'orders', 'fresh' => true])
        ->and($run->trigger)->toBe('manual')
        ->and($run->user_id)->toBe(auth()->id());
    Queue::assertPushed(RunDeployment::class);
});

it('says why the dialog can’t run it, and asks for a passkey first when the action does', function () {
    $target = actionTarget();
    $action = projectAction($target->project, PAGE_ACTION, 'reindex-search');
    Deployment::factory()->create(['target_id' => $target->id, 'kind' => DeploymentKind::Deploy, 'status' => DeploymentStatus::Running]);

    Livewire::test(RunActionDialog::class, ['projectId' => $target->project_id])
        ->dispatch('open-action-dialog', actionId: $action->id)
        ->call('run')
        ->assertSet('problem', fn (string $problem) => str_contains($problem, 'is already going on'));

    $guarded = projectAction($target->project, "name: Flush\nconfirm: passkey\nsteps: [ls]\n", 'flush');

    Livewire::test(RunActionDialog::class, ['projectId' => $target->project_id])
        ->dispatch('open-action-dialog', actionId: $guarded->id)
        ->assertRedirect(route('password.confirm'))
        ->assertSet('open', false);
});

it('shows an action run with its inputs masked and who started it, and runs it again', function (string $theme) {
    auth()->user()->forceFill(['ui' => ['theme' => $theme]])->save();
    $target = actionTarget();
    $action = projectAction($target->project, "name: Rotate\ninputs: { token: secret, days: { type: number, default: 7 } }\nsteps: [ls]\n", 'rotate');
    $run = app(StartAction::class)($action, $target, ['token' => 'masked-value'], auth()->user());
    $run->update(['status' => DeploymentStatus::Failed, 'finished_at' => now()]);

    $this->get(route('deployments.show', $run))
        ->assertOk()
        ->assertSee('Rotate')
        ->assertSee('token=••••••', false)
        ->assertSee('days=7')
        ->assertDontSee('masked-value');

    Livewire::test(RunPage::class, ['deployment' => $run])
        ->call('retry')
        ->assertDispatched('open-action-dialog', actionId: $action->id, targetId: $target->id, inputs: ['days' => 7]);
})->with(['classic', 'console']);
