<?php

use App\Deploy\Logging\StepLogger;
use App\Deploy\Pipeline\PipelineRunner;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Events\DeploymentLogged;
use App\Events\DeploymentUpdated;
use App\Jobs\RunDeployment;
use App\Livewire\Console\Tail;
use App\Livewire\Deployments\Show;
use App\Models\Deployment;
use App\Models\User;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\Testing\FakeSshClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

// A log pane loads a run's last LOG_PANE_LINES lines, not the whole log, and
// the run's line count is written with the heartbeat, per step and at the end.
beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Event::fake([DeploymentUpdated::class, DeploymentLogged::class]);
});

/**
 * A finished run with $count lines in its log.
 */
function longRun(int $count): Deployment
{
    $run = Deployment::factory()->create(['kind' => DeploymentKind::Deploy, 'status' => DeploymentStatus::Succeeded, 'log_lines' => $count]);

    foreach (array_chunk(range(1, $count), 1000) as $chunk) {
        DB::table('deployment_logs')->insert(array_map(fn (int $seq) => [
            'deployment_id' => $run->id, 'seq' => $seq, 'step_key' => 'build', 'stream' => 'out', 'line' => "line {$seq}", 'logged_at' => now(),
        ], $chunk));
    }

    return $run;
}

it('starts a log pane near the end of a long run, and pages on from there', function () {
    $run = longRun(12_000);
    $page = new Show;
    $page->deployment = $run;

    $first = $page->linesAfter(0);
    $next = $page->linesAfter(end($first)[0]);

    expect($first)->toHaveCount(Deployment::LOG_PAGE)
        ->and($first[0][0])->toBe(12_000 - Deployment::LOG_PANE_LINES + 1)
        ->and($first[0][4])->toBe('line 7001')
        ->and($next[0][0])->toBe(7001 + Deployment::LOG_PAGE)
        // A pane that already has lines further on gets what follows them.
        ->and($page->linesAfter(11_990))->toHaveCount(10);

    Livewire::test(Tail::class, ['arg' => (string) $run->id])
        ->call('linesAfter', 0)
        ->assertReturned(fn (array $lines) => $lines[0][0] === 7001 && count($lines) === Deployment::LOG_PAGE);
});

it('starts a short run’s log at its first line', function () {
    $page = new Show;
    $page->deployment = longRun(12);

    expect(array_column($page->linesAfter(0), 0))->toBe(range(1, 12));
});

it('writes the line count with the step and at the end, not with every batch', function () {
    $run = Deployment::factory()->create();
    $log = new StepLogger($run);

    $log->forStep('build');
    $log->line('one');
    $log->line('two');
    $log->flush();

    expect($run->refresh()->log_lines)->toBe(0)
        ->and($run->logLines()->count())->toBe(2);

    $log->forStep('migrate');
    expect($run->refresh()->log_lines)->toBe(2);

    $log->note('three');
    $log->close();
    expect($run->refresh()->log_lines)->toBe(3);
});

it('counts every line of a finished run, however it ended', function () {
    app()->instance(SshClient::class, fakeDeployScripts(new FakeSshClient));
    $finished = Deployment::factory()->create(['target_id' => demoTarget()->id]);
    app(PipelineRunner::class)->run($finished);

    expect($finished->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($finished->log_lines)->toBeGreaterThan(5)
        ->and($finished->log_lines)->toBe((int) DB::table('deployment_logs')->where('deployment_id', $finished->id)->max('seq'));

    // A worker that died between heartbeats left lines the count never got.
    $died = longRun(40);
    $died->update(['status' => DeploymentStatus::Running, 'log_lines' => 30]);
    (new RunDeployment($died->id))->failed(new RuntimeException('Worker killed'));

    expect($died->refresh()->log_lines)->toBe(40);
});
