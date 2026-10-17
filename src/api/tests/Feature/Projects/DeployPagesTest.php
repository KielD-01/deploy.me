<?php

use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Enums\ReleaseStatus;
use App\Jobs\RunDeployment;
use App\Livewire\Deployments\Show as RunPage;
use App\Livewire\Projects\DeployDialog;
use App\Livewire\Projects\Releases;
use App\Livewire\Projects\Targets;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use App\Models\Server;
use App\Models\Target;
use App\Models\User;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->create());
    config(['deployme.project_roots' => [sys_get_temp_dir()]]);
    Queue::fake();
});

/**
 * A local project with two commits, the newer one adding a migration.
 */
function twoCommitTarget(): Target
{
    $repo = tempGitRepo(['index.php' => 'v1']);
    mkdir("{$repo}/database/migrations", 0755, true);
    file_put_contents("{$repo}/database/migrations/2026_09_11_000000_create_orders_table.php", '<?php');

    $git = fn (string ...$args) => Process::path($repo)->env([
        'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1',
        'GIT_AUTHOR_NAME' => 'Test', 'GIT_AUTHOR_EMAIL' => 'test@example.com',
        'GIT_COMMITTER_NAME' => 'Test', 'GIT_COMMITTER_EMAIL' => 'test@example.com',
    ])->run(['git', ...$args])->throw();
    $git('add', '--all');
    $git('commit', '--quiet', '-m', 'Add the orders table');

    return demoTarget([
        'source' => 'local', 'local_path' => $repo, 'github_owner' => null, 'github_repo' => null,
        'delivery_strategies' => [['key' => 'push_archive', 'enabled' => true]],
    ]);
}

function revParse(Target $target, string $ref): string
{
    return trim(Process::path($target->project->local_path)->run(['git', 'rev-parse', $ref])->throw()->output());
}

/**
 * A deploy of release 20260902-100000 over 20260901-100000 whose health check
 * failed, so it switched back.
 */
function rolledBackDeploy(): Deployment
{
    $target = demoTarget();
    $previous = Release::factory()->create(['target_id' => $target->id, 'name' => '20260901-100000']);
    $release = Release::factory()->create(['target_id' => $target->id, 'name' => '20260902-100000', 'status' => ReleaseStatus::Failed]);
    $target->update(['current_release_id' => $previous->id]);
    $run = Deployment::factory()->create([
        'target_id' => $target->id, 'kind' => DeploymentKind::Deploy, 'status' => DeploymentStatus::RolledBack,
        'strategy' => 'push_archive', 'commit_sha' => str_repeat('b', 40), 'options' => ['backup' => true],
        'release_id' => $release->id, 'previous_release_id' => $previous->id,
    ]);

    $steps = ['resolve' => 'Resolve commit', 'upload' => 'Upload files', 'activate' => 'Switch current', 'health' => 'Health check', 'rollback' => 'Switch back to 20260901-100000', 'unlock' => 'Clean up'];
    foreach (array_keys($steps) as $position => $key) {
        $run->steps()->create(['key' => $key, 'label' => $steps[$key], 'position' => $position, 'status' => $key === 'health' ? 'failed' : 'succeeded']);
    }

    return $run;
}

it('shows the branch’s commits, what’s live, and what will deliver them, then deploys the latest', function () {
    $target = twoCommitTarget();
    $live = Release::factory()->create(['target_id' => $target->id, 'commit_sha' => revParse($target, 'HEAD~1')]);
    $target->update(['current_release_id' => $live->id]);

    Livewire::test(DeployDialog::class, ['projectId' => $target->project_id])
        ->call('openFor', $target->id)
        // What stops the spinner on the button that asked for the dialog.
        ->assertDispatched('deploy-dialog-opened')
        ->assertSet('open', true)
        ->assertSee('Deploy demo')
        ->assertSee(['Add the orders table', 'Adds a migration', 'First commit', 'Live on '.$target->server->name])
        ->assertSee('1 commit since the live release · 1 adds a migration')
        ->assertSee(['Push archive', 'Will be used', 'Server git · off for this project'])
        ->assertSee('Deploy '.substr(revParse($target, 'HEAD'), 0, 7).' to '.$target->server->name)
        ->call('deploy')
        ->assertRedirect();

    $run = Deployment::query()->sole();
    expect($run->kind)->toBe(DeploymentKind::Deploy)
        ->and($run->options)->toBe([]);
    Queue::assertPushed(RunDeployment::class);
});

it('deploys an older commit when you pick it', function () {
    $target = twoCommitTarget();
    $older = revParse($target, 'HEAD~1');

    Livewire::test(DeployDialog::class, ['projectId' => $target->project_id])
        ->call('openFor', $target->id)
        ->call('pickCommit', $older)
        ->assertSee('Deploy '.substr($older, 0, 7).' to '.$target->server->name)
        ->call('deploy');

    expect(Deployment::query()->sole()->options)->toBe(['commit' => $older]);
});

it('starts one run however many times Deploy is asked for, and goes to it each time', function () {
    $target = twoCommitTarget();

    $dialog = Livewire::test(DeployDialog::class, ['projectId' => $target->project_id])
        ->call('openFor', $target->id)
        ->call('deploy');

    $run = Deployment::query()->sole();
    $dialog->assertRedirect(route('deployments.show', $run))
        // A double click, or the same request sent again.
        ->call('deploy')
        ->assertHasNoErrors()
        ->assertSet('problem', null)
        ->assertRedirect(route('deployments.show', $run));

    expect(Deployment::query()->count())->toBe(1);
    Queue::assertPushed(RunDeployment::class, 1);
});

it('says so when a target is busy', function () {
    $target = twoCommitTarget();
    Deployment::factory()->create(['target_id' => $target->id, 'status' => DeploymentStatus::Running]);

    Livewire::test(DeployDialog::class, ['projectId' => $target->project_id])
        ->call('openFor', $target->id)
        ->call('deploy')
        ->assertSee($target->server->name.' is busy with another run.');
});

it('lists a target’s releases and rolls back to one', function () {
    $target = demoTarget();
    $old = Release::factory()->create(['target_id' => $target->id, 'name' => '20260901-100000']);
    $live = Release::factory()->create(['target_id' => $target->id, 'name' => '20260902-100000']);
    Release::factory()->create(['target_id' => $target->id, 'name' => '20260801-100000', 'status' => ReleaseStatus::Pruned, 'pruned_at' => now()]);
    $target->update(['current_release_id' => $live->id]);

    Livewire::test(Releases::class, ['projectId' => $target->project_id])
        ->assertSee(['20260902-100000', 'Current', '20260901-100000', 'Ready', '20260801-100000', 'Removed', 'Roll back'])
        ->assertSee('2 of 5 kept on '.$target->server->name)
        ->call('rollBack', $old->id)
        ->assertRedirect();

    $run = Deployment::query()->sole();
    expect($run->kind)->toBe(DeploymentKind::Rollback)
        ->and($run->options)->toBe(['release_id' => $old->id]);
});

it('won’t roll back to a release that was removed', function () {
    $target = demoTarget();
    $pruned = Release::factory()->create(['target_id' => $target->id, 'status' => ReleaseStatus::Pruned, 'pruned_at' => now()]);

    Livewire::test(Releases::class, ['projectId' => $target->project_id])
        ->call('rollBack', $pruned->id)
        ->assertSee('isn’t on the server any more');

    expect(Deployment::query()->count())->toBe(0);
});

it('shows each target’s live release and the run in progress', function () {
    $target = demoTarget();
    $release = Release::factory()->create(['target_id' => $target->id, 'name' => '20260902-100000', 'commit_sha' => str_repeat('a', 40)]);
    $target->update(['current_release_id' => $release->id]);
    $running = Deployment::factory()->create(['target_id' => $target->id, 'kind' => DeploymentKind::Deploy, 'status' => DeploymentStatus::Running]);

    Livewire::test(Targets::class, ['projectId' => $target->project_id])
        ->assertSee('20260902-100000 · aaaaaaa')
        ->assertSee('Deploying '.$running->number());
});

it('deploys right after assigning', function () {
    $server = Server::factory()->create(['default_deploy_path' => '/opt/srv']);
    $project = Project::factory()->create();

    Livewire::test(Targets::class, ['projectId' => $project->id])
        ->call('startAssigning')
        ->call('pickServer', $server->id)
        ->assertSet('deployAfter', true)
        ->call('assign')
        ->assertRedirect();

    expect(Deployment::query()->sole()->kind)->toBe(DeploymentKind::Deploy);
});

it('offers to deploy the same commit again after a failed run', function () {
    $failed = Deployment::factory()->create([
        'target_id' => demoTarget()->id, 'kind' => DeploymentKind::Deploy, 'status' => DeploymentStatus::Failed,
        'strategy' => 'push_archive', 'commit_sha' => str_repeat('b', 40), 'outcome' => 'Migrate failed with exit code 1.',
    ]);

    Livewire::test(RunPage::class, ['deployment' => $failed])
        ->assertSee('Deploy bbbbbbb again')
        ->call('retry')
        ->assertRedirect();

    expect(Deployment::query()->latest('id')->first()->options)->toBe(['commit' => str_repeat('b', 40)]);
});

it('offers to retry a rolled-back deploy from its health check, and starts the retry', function () {
    $run = rolledBackDeploy();

    Livewire::test(RunPage::class, ['deployment' => $run])
        ->assertSee(['Retry from health check', 'Deploy bbbbbbb again', "Switch {$run->server->name} to release 20260902-100000 again, and retry from health check?"])
        ->call('retryFrom')
        ->assertRedirect();

    $retry = Deployment::query()->latest('id')->first();

    expect($retry->id)->not->toBe($run->id)
        ->and($retry->kind)->toBe(DeploymentKind::Deploy)
        ->and($retry->trigger)->toBe('retry')
        ->and($retry->retry_of_id)->toBe($run->id)
        ->and($retry->resume_from)->toBe('health')
        ->and($retry->strategy)->toBe('push_archive')
        ->and($retry->commit_sha)->toBe(str_repeat('b', 40))
        ->and($retry->options)->toBe(['backup' => true]);
    Queue::assertPushed(RunDeployment::class, fn (RunDeployment $job) => $job->deploymentId === $retry->id);

    // The retry says what it retries; the run it retries points at it.
    $this->get(route('deployments.show', $retry))->assertOk()->assertSeeText("Retry of {$run->number()} from health check");
    $this->get(route('deployments.show', $run))->assertOk()->assertSeeText("Retried as {$retry->number()}")->assertDontSee('Retry from health check');
    $this->get('/deployments')->assertOk()->assertSee("Retry of {$run->number()}");
});

it('only offers a fresh deploy when there’s no release to pick up where it stopped', function () {
    $run = rolledBackDeploy();

    // In place, there are no release folders to go back to.
    $run->project->update(['versionable' => false]);
    Livewire::test(RunPage::class, ['deployment' => $run->refresh()])
        ->assertSee('Deploy bbbbbbb again')
        ->assertDontSee('Retry from');

    // Another release went live since.
    $run->project->update(['versionable' => true]);
    $run->target->update(['current_release_id' => Release::factory()->create(['target_id' => $run->target_id, 'name' => '20260903-100000'])->id]);
    Livewire::test(RunPage::class, ['deployment' => $run->refresh()])
        ->assertSee('Deploy bbbbbbb again')
        ->assertDontSee('Retry from');
});

it('offers to switch back to the release a deploy replaced', function () {
    $target = demoTarget();
    $previous = Release::factory()->create(['target_id' => $target->id, 'name' => '20260901-100000']);
    $new = Release::factory()->create(['target_id' => $target->id, 'name' => '20260902-100000']);
    $target->update(['current_release_id' => $new->id, 'previous_release_id' => $previous->id]);
    $run = Deployment::factory()->create([
        'target_id' => $target->id, 'kind' => DeploymentKind::Deploy, 'status' => DeploymentStatus::Succeeded,
        'release_id' => $new->id, 'previous_release_id' => $previous->id,
    ]);

    Livewire::test(RunPage::class, ['deployment' => $run])
        ->assertSee('Roll back to 20260901-100000')
        ->call('rollBack')
        ->assertRedirect();

    $next = Deployment::query()->latest('id')->first();
    expect($next->kind)->toBe(DeploymentKind::Rollback)
        ->and($next->options)->toBe(['release_id' => $previous->id]);
});

it('names the file the pins are written to, and offers a rollback in place only with pins', function () {
    $target = demoTarget(['versionable' => false, 'dockerized' => true, 'stack_name' => 'demo', 'compose_files' => ['compose.yaml']]);
    $pinned = Release::factory()->create([
        'target_id' => $target->id, 'name' => '20260901-100000', 'layout' => Release::IN_PLACE,
        'path' => $target->path, 'image_pins' => ['IMAGE_TAG' => '9e1f2aa'],
    ]);
    $bare = Release::factory()->create(['target_id' => $target->id, 'name' => '20260902-100000', 'layout' => Release::IN_PLACE, 'path' => $target->path]);
    $live = Release::factory()->create([
        'target_id' => $target->id, 'name' => '20260903-100000', 'layout' => Release::IN_PLACE,
        'path' => $target->path, 'image_pins' => ['IMAGE_TAG' => 'ccccccc'],
    ]);
    $target->update(['current_release_id' => $live->id]);

    Livewire::test(Releases::class, ['projectId' => $target->project_id])
        ->assertSee('.deployme-pins.env')
        ->assertDontSee('/.env<')
        ->call('rollBack', $bare->id)
        ->assertSee('didn’t record which images it ran')
        ->call('rollBack', $pinned->id)
        ->assertRedirect();

    expect(Deployment::query()->sole()->options)->toBe(['release_id' => $pinned->id]);
});

/**
 * Commits every change in a repository the tests made.
 */
function commitAll(string $repo, string $message): void
{
    Process::path($repo)->env([
        'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1',
        'GIT_AUTHOR_NAME' => 'Test', 'GIT_AUTHOR_EMAIL' => 'test@example.com',
        'GIT_COMMITTER_NAME' => 'Test', 'GIT_COMMITTER_EMAIL' => 'test@example.com',
    ])->run(['git', 'commit', '--quiet', '--all', '-m', $message])->throw();
}

it('lists a commit that only changes files, and marks the one that adds a migration', function () {
    $target = twoCommitTarget();
    file_put_contents("{$target->project->local_path}/index.php", 'v2');
    commitAll($target->project->local_path, 'Polish the page');

    $commits = Livewire::test(DeployDialog::class, ['projectId' => $target->project_id])
        ->call('openFor', $target->id)
        ->get('commits');

    // The newest commit adds nothing, and the list used to start below it.
    expect(array_column($commits, 'subject'))->toBe(['Polish the page', 'Add the orders table', 'First commit'])
        ->and(array_column($commits, 'migration'))->toBe([false, true, false])
        ->and($commits[0]['sha'])->toBe(revParse($target, 'HEAD'));
});
