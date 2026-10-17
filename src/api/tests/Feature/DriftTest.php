<?php

use App\Jobs\RefreshDrift;
use App\Livewire\Dashboard;
use App\Models\Project;
use App\Models\Release;
use App\Models\Server;
use App\Models\Target;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->create());
    config(['deployme.project_roots' => [sys_get_temp_dir()]]);
});

/**
 * A target of a local repository whose live release is at $sha.
 */
function driftTarget(string $repo, ?string $sha): Target
{
    $project = Project::factory()->local($repo)->create();
    $target = Target::factory()->create(['project_id' => $project->id, 'server_id' => Server::factory()->create()->id]);

    if ($sha !== null) {
        $target->update(['current_release_id' => Release::factory()->create(['target_id' => $target->id, 'commit_sha' => $sha])->id]);
    }

    return $target->refresh();
}

function driftRepoHead(string $repo): string
{
    return trim(Process::path($repo)->run(['git', 'rev-parse', 'HEAD'])->throw()->output());
}

it('measures each live target in a job and keeps the answer on the target', function () {
    $repo = tempGitRepo(['index.php' => '<?php']);
    $first = driftRepoHead($repo);
    commitFile($repo, 'a.txt', 'a');
    commitFile($repo, 'b.txt', 'b');
    file_put_contents("{$repo}/draft.txt", 'not committed');
    $target = driftTarget($repo, $first);

    RefreshDrift::dispatchSync();

    $target->refresh();

    expect($target->drift_head)->toBe(driftRepoHead($repo))
        ->and($target->drift_behind)->toBe(2)
        ->and($target->drift_dirty)->toBe(1)
        ->and($target->drift_deployed)->toBe($first)
        ->and($target->drift_checked_at)->not->toBeNull()
        ->and($target->drift())->toBe(['head' => driftRepoHead($repo), 'behind' => 2, 'dirty' => 1]);
});

it('measures one project when asked to, and leaves archived targets alone', function () {
    $repo = tempGitRepo(['index.php' => '<?php']);
    $other = driftTarget(tempGitRepo(['index.php' => '<?php']), null);
    $mine = driftTarget($repo, null);
    $archived = driftTarget(tempGitRepo(['index.php' => '<?php']), null);
    $archived->update(['archived_at' => now()]);

    RefreshDrift::dispatchSync($mine->project_id);

    expect($mine->refresh()->drift_checked_at)->not->toBeNull()
        ->and($other->refresh()->drift_checked_at)->toBeNull()
        ->and($archived->refresh()->drift_checked_at)->toBeNull();
});

it('draws the dashboard from what the job stored and runs no git', function () {
    $sha = str_repeat('a', 40);
    $head = str_repeat('b', 40);
    // A real repository, so a dashboard that asked git would find something to run.
    $target = driftTarget(tempGitRepo(['index.php' => '<?php']), $sha);
    $target->forceFill(['drift_head' => $head, 'drift_deployed' => $sha, 'drift_behind' => 3, 'drift_dirty' => 2, 'drift_checked_at' => now()])->save();

    Process::preventStrayProcesses();
    Process::fake();

    Livewire::test(Dashboard::class)
        ->assertSee('3 behind')
        ->assertSee(substr($head, 0, 7))
        ->assertSee('3 commits ahead')
        ->assertSee('2 uncommitted files');

    Process::assertNothingRan();
});

it('doesn’t trust a count made for a release that has since been replaced', function () {
    $old = str_repeat('a', 40);
    $new = str_repeat('c', 40);
    $head = str_repeat('b', 40);
    $target = driftTarget('/nowhere/atelier', $new);
    $target->forceFill(['drift_head' => $head, 'drift_deployed' => $old, 'drift_behind' => 3, 'drift_checked_at' => now()])->save();

    expect($target->refresh()->drift()['behind'])->toBeNull();

    // A deploy that caught up with the head needs no new measurement to say so.
    $target->currentRelease->update(['commit_sha' => $head]);

    expect($target->refresh()->drift()['behind'])->toBe(0);
});

it('shows the last CI poll’s commit until a target has been measured', function () {
    $sha = str_repeat('a', 40);
    $target = driftTarget('/nowhere/atelier', $sha);
    $target->project->forceFill(['ci_run' => ['sha' => $sha]])->save();

    expect($target->refresh()->drift())->toBe(['head' => $sha, 'behind' => 0, 'dirty' => null]);
});

it('has the scheduler queue it every minute', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('refresh-drift');
});
