<?php

use App\Actions\Deployments\StartDeployment;
use App\Deploy\DeploymentRefused;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Livewire\Deployments\Show as RunPage;
use App\Livewire\Projects\Releases;
use App\Mcp\ToolFailed;
use App\Mcp\Tools\Deploy as DeployTool;
use App\Models\Deployment;
use App\Models\ProjectEnvFile;
use App\Models\Release;
use App\Models\Target;
use App\Models\User;
use App\Terminal\Shell;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

// StartDeployment is the one place that says whether a target can take a run
// and with which options: the pages, the shell, MCP and CI all go through it.
beforeEach(function () {
    Queue::fake();
    $this->withoutVite();
    $this->actingAs(User::factory()->create());
});

const STILL_RUNS = 'Box still runs from an earlier deployment. Convert that folder first: it’s on the Targets tab.';

/**
 * A versionable project whose folder an earlier deployment still runs from.
 */
function unconvertedTarget(): Target
{
    $target = demoTarget();
    $target->server->update(['name' => 'Box']);
    $target->update(['folder_scan' => ['foreign' => ['count' => 4, 'names' => ['.env'], 'seen_at' => now()->toIso8601String()]]]);

    return $target->refresh();
}

/**
 * A Docker project that migrates, with or without a Postgres database its
 * env files name (what a backup needs).
 */
function migratingTarget(bool $database = true): Target
{
    $target = demoTarget(['dockerized' => true, 'stack_name' => 'demo', 'compose_files' => ['compose.yaml'], 'app_services' => ['app'], 'migrate_service' => 'app']);

    if ($database) {
        $target->project->envFiles()->create(['path' => '.env', 'role' => 'app', ...ProjectEnvFile::contentAttributes("DB_CONNECTION=pgsql\nDB_DATABASE=demo\n")]);
    }

    return $target;
}

it('refuses a deploy or a redeploy into a folder that needs converting, and nothing else', function () {
    $target = unconvertedTarget();
    $start = app(StartDeployment::class);

    expect(fn () => $start($target, DeploymentKind::Deploy))->toThrow(DeploymentRefused::class, STILL_RUNS)
        ->and(fn () => $start($target, DeploymentKind::Redeploy, options: ['commit' => str_repeat('a', 40)]))->toThrow(DeploymentRefused::class, STILL_RUNS)
        ->and(Deployment::query()->count())->toBe(0);

    // Preparing the folder, adopting it and converting it still go.
    expect($start($target, DeploymentKind::Bootstrap)->kind)->toBe(DeploymentKind::Bootstrap);
});

it('fills in the backup and migration defaults wherever a deploy starts', function () {
    $start = app(StartDeployment::class);
    $migrating = migratingTarget();

    expect($start($migrating, DeploymentKind::Deploy, options: ['commit' => 'abc1234'])->options)
        ->toBe(['commit' => 'abc1234', 'backup' => true, 'skip_migrations' => false]);
    Deployment::query()->delete();

    expect($start($migrating, DeploymentKind::Deploy, options: ['backup' => false, 'skip_migrations' => true])->options)
        ->toBe(['backup' => false, 'skip_migrations' => true]);
    Deployment::query()->delete();

    // No database to dump: no backup, whatever was asked.
    $migrating->project->envFiles()->delete();
    expect($start($migrating, DeploymentKind::Redeploy, options: ['backup' => true])->options)
        ->toBe(['backup' => false, 'skip_migrations' => false]);
    Deployment::query()->delete();

    // A run that doesn't deploy, and a project that doesn't migrate, keep what they were given.
    expect(StartDeployment::options($migrating->project, DeploymentKind::Rollback, ['release_id' => 7]))->toBe(['release_id' => 7]);
    $migrating->project->update(['migrate_service' => null]);
    expect($start($migrating->refresh(), DeploymentKind::Deploy)->options)->toBe([]);
});

it('says why over MCP, before anything is queued', function () {
    unconvertedTarget();

    expect(fn () => app(DeployTool::class)->call(['project' => 'demo']))->toThrow(ToolFailed::class, STILL_RUNS);
    Queue::assertNothingPushed();
});

it('says why in the Console shell', function () {
    unconvertedTarget();

    $out = app(Shell::class)->answer(['command' => 'deploy', 'args' => ['demo', 'box'], 'flags' => ['commit' => ''], 'key' => 'confirm'], 'y');

    expect(implode("\n", array_map(fn (array $line) => implode('', array_column($line, 0)), $out->lines)))->toContain(STILL_RUNS)
        ->and(Deployment::query()->count())->toBe(0);
});

it('says why on the Releases tab and the run page', function () {
    $target = unconvertedTarget();
    $release = Release::factory()->create(['target_id' => $target->id, 'commit_sha' => str_repeat('a', 40)]);
    $failed = Deployment::factory()->create(['target_id' => $target->id, 'kind' => DeploymentKind::Deploy, 'status' => DeploymentStatus::Failed]);

    Livewire::test(Releases::class, ['projectId' => $target->project_id])
        ->call('redeploy', $release->id)
        ->assertSet('problem', STILL_RUNS)
        ->assertNoRedirect();

    Livewire::test(RunPage::class, ['deployment' => $failed])
        ->call('retry')
        ->assertSet('problem', STILL_RUNS)
        ->assertNoRedirect();

    expect(Deployment::query()->count())->toBe(1);
});
