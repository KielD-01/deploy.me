<?php

use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Jobs\ProbeServer;
use App\Jobs\RunDeployment;
use App\Livewire\Console\Git;
use App\Livewire\Console\Session;
use App\Livewire\Console\Shell as ShellPane;
use App\Mcp\Tools\PlanDeploy;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use App\Models\Server;
use App\Models\Target;
use App\Models\User;
use App\Terminal\Layout;
use App\Terminal\Output;
use App\Terminal\Shell;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    // What a deploy would ship, without asking GitHub.
    app()->instance(PlanDeploy::class, new class extends PlanDeploy
    {
        public function call(array $arguments): array
        {
            return ['project' => $arguments['project'], 'strategy' => 'push_archive', 'waits_for' => null, 'fallback_without_waiting' => null, 'commit' => 'abc1234def', 'message' => 'Fix it', 'reason' => 'The commit is on the branch.', 'trace' => []];
        }
    });
});

function shellText(Output $out): string
{
    return implode("\n", array_map(fn (array $line) => implode('', array_column($line, 0)), $out->lines));
}

function shellTarget(string $project = 'shop', string $server = 'prod-eu'): Target
{
    $server = Server::query()->where('slug', $server)->first() ?? Server::factory()->create(['slug' => $server, 'ssh_alias' => $server]);
    $project = Project::query()->where('slug', $project)->first() ?? Project::factory()->create(['slug' => $project, 'name' => $project]);

    return Target::factory()->create(['server_id' => $server->id, 'project_id' => $project->id, 'base_dir' => '/opt/srv']);
}

it('lists its commands and refuses one it doesn’t know', function () {
    expect(shellText(app(Shell::class)->run('help')))->toContain('deploy', 'rollback', '⌃b %')
        ->and(shellText(app(Shell::class)->run('frobnicate')))->toContain('frobnicate: no such command');
});

it('prints every target with its live commit and last run', function () {
    $target = shellTarget();
    $release = Release::factory()->create(['target_id' => $target->id, 'commit_sha' => str_repeat('a', 40)]);
    $target->update(['current_release_id' => $release->id]);

    $text = shellText(app(Shell::class)->run('status'));

    expect($text)->toContain('shop@prod-eu', 'aaaaaaa', '1 targets · 0 running');
});

it('walks a deploy through its questions, shows the plan and starts only on yes', function () {
    shellTarget('shop', 'prod-eu');
    shellTarget('shop', 'staging');
    $shell = app(Shell::class);

    $ask = $shell->run('deploy')->ask;
    expect($ask['question'])->toBe('Project')->and(array_column($ask['options'], 'value'))->toBe(['shop']);

    $ask = $shell->answer($ask['state'], 'shop')->ask;
    expect($ask['question'])->toBe('Server')->and(array_column($ask['options'], 'value'))->toBe(['prod-eu', 'staging']);

    $plan = $shell->answer($ask['state'], 'staging');
    expect(shellText($plan))->toContain('┌─ plan', 'shop → staging', 'push_archive')
        ->and($plan->ask['type'])->toBe('confirm')
        ->and(Deployment::query()->count())->toBe(0);

    expect(shellText($shell->answer($plan->ask['state'], 'n')))->toContain('Nothing done.')
        ->and(Deployment::query()->count())->toBe(0);

    $started = $shell->answer($plan->ask['state'], 'y');
    $run = Deployment::query()->sole();

    expect(shellText($started))->toContain('started #'.$run->id)
        ->and($started->open)->toBe(['kind' => 'tail', 'arg' => (string) $run->id])
        ->and($run->kind)->toBe(DeploymentKind::Deploy)
        ->and($run->trigger)->toBe('console')
        ->and($run->server->slug)->toBe('staging');
    Queue::assertPushed(RunDeployment::class);
});

it('never takes a flag as the yes', function () {
    shellTarget();

    $out = app(Shell::class)->run('deploy shop prod-eu -y --yes');

    expect($out->ask['type'])->toBe('confirm')->and(Deployment::query()->count())->toBe(0);
});

it('rolls back to a release you pick, after asking', function () {
    $target = shellTarget();
    $old = Release::factory()->create(['target_id' => $target->id, 'name' => '20261015-120000']);
    $live = Release::factory()->create(['target_id' => $target->id, 'name' => '20261016-120000']);
    $target->update(['current_release_id' => $live->id, 'previous_release_id' => $old->id]);
    $shell = app(Shell::class);

    $ask = $shell->run('rollback shop')->ask;
    expect(array_column($ask['options'], 'value'))->toBe(['20261015-120000']);

    $confirm = $shell->answer($ask['state'], '20261015-120000')->ask;
    $shell->answer($confirm['state'], 'y');

    $run = Deployment::query()->sole();
    expect($run->kind)->toBe(DeploymentKind::Rollback)->and($run->options)->toBe(['release_id' => $old->id]);
});

it('cancels a run after asking', function () {
    $target = shellTarget();
    $run = Deployment::factory()->create(['target_id' => $target->id, 'kind' => DeploymentKind::Deploy]);
    $shell = app(Shell::class);

    $ask = $shell->run('cancel #'.$run->id)->ask;
    expect($ask['question'])->toContain('Cancel #'.$run->id);

    $shell->answer($ask['state'], 'yes');
    expect($run->fresh()->status)->toBe(DeploymentStatus::Cancelled);
});

it('probes, opens panes, goes to pages and completes names', function () {
    shellTarget();
    $shell = app(Shell::class);

    $shell->run('probe prod-eu');
    Queue::assertPushed(ProbeServer::class);

    expect($shell->run('open htop prod-eu')->open)->toBe(['kind' => 'htop', 'arg' => 'prod-eu'])
        ->and(shellText($shell->run('open htop nowhere')))->toContain('No server “nowhere”')
        ->and($shell->run('go servers')->visit)->toBe(route('servers.index'))
        ->and($shell->complete('dep'))->toBe(['deploy'])
        ->and($shell->complete('deploy s'))->toBe(['deploy shop'])
        ->and($shell->complete('open htop p'))->toBe(['open htop prod-eu']);
});

it('says so when nothing has a release to roll back, instead of an empty pick', function () {
    shellTarget();

    $out = app(Shell::class)->run('rollback');

    expect($out->ask)->toBeNull()
        ->and(shellText($out))->toContain('Nothing is live yet');
});

it('opens a pane on what a name found, however it was typed', function () {
    $target = shellTarget();
    $target->project->update(['name' => 'ShopFront']);
    $target->server->update(['name' => 'Prod EU', 'ssh_alias' => 'prod-eu-alias']);
    $shell = app(Shell::class);

    // The panes look projects and servers up by slug.
    expect($shell->run('open git shopfront')->open)->toBe(['kind' => 'git', 'arg' => 'shop'])
        ->and($shell->run('open htop prod-eu-alias')->open)->toBe(['kind' => 'htop', 'arg' => 'prod-eu']);

    Livewire::test(Git::class, ['pane' => 'p1', 'arg' => $shell->run('open git shopfront')->open['arg']])
        ->assertSet('projectId', $target->project_id);
});

it('runs commands from its pane, asks there, and opens the run in another pane', function () {
    shellTarget();

    $pane = Livewire::test(ShellPane::class, ['pane' => 'p0'])
        ->call('submit', 'deploy shop prod-eu')
        ->assertSee('┌─ plan')
        ->assertSee('Deploy shop to prod-eu?');

    $pane->call('submit', 'y')
        ->assertDispatched('console-open', kind: 'tail', from: 'p0');

    expect(Deployment::query()->count())->toBe(1);

    $pane->call('submit', 'clear')->assertSet('screen', []);
});

it('keeps the session’s panes on the account as you split and close them', function () {
    $session = Livewire::test(Session::class)
        ->call('split', 'p0', 'col', 'status');

    expect(collect($this->user->fresh()->ui['layouts']['current']['children'][0]['children'] ?? [])->pluck('kind')->all())->toBe(['shell', 'status']);

    $session->call('close', 'p4');
    $session->dispatch('console-open', kind: 'htop', arg: 'prod-eu');

    $leaves = Layout::leaves($this->user->fresh()->ui['layouts']['current']);
    expect(collect($leaves)->firstWhere('kind', 'htop')['arg'])->toBe('prod-eu')
        ->and(collect($leaves)->pluck('kind')->all())->not->toContain('status');
});
