<?php

use App\Deploy\Pipeline\PipelineRunner;
use App\Enums\DeploymentStatus;
use App\Events\DeploymentLogged;
use App\Events\DeploymentUpdated;
use App\Livewire\Deployments\Show;
use App\Models\Deployment;
use App\Models\User;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\Testing\FakeSshClient;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->create());
    Event::fake([DeploymentUpdated::class, DeploymentLogged::class]);
});

function finishedRun(): Deployment
{
    app()->instance(SshClient::class, fakeDeployScripts(new FakeSshClient));
    $deployment = Deployment::factory()->create(['target_id' => demoTarget()->id]);
    app(PipelineRunner::class)->run($deployment);

    return $deployment->refresh();
}

it('shows a run with its steps and outcome', function () {
    $run = finishedRun();

    $this->get(route('deployments.show', $run))
        ->assertOk()
        ->assertSee("{$run->number()}")
        ->assertSee('Succeeded')
        ->assertSee(['Pre-flight checks', '97.7 GB free', 'Acquire deploy lock', 'Create folders', 'Record state', 'Clean up'])
        ->assertSee('/opt/srv/demo is ready for deploys.')
        ->assertSee('Mode');
});

it('serves the log lines the page missed', function () {
    $run = finishedRun();
    $component = new Show;
    $component->deployment = $run;

    $lines = $component->linesAfter(0);
    $tail = $component->linesAfter($lines[1][0]);

    expect($lines)->not->toBeEmpty()
        ->and($lines[1][4])->toBe('▸ Pre-flight checks')
        ->and($lines[1][2])->toBe('preflight')
        ->and($tail[0][0])->toBe($lines[2][0]);
});

it('downloads the whole log as text', function () {
    $run = finishedRun();

    $response = $this->get(route('deployments.log', $run));

    $response->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    expect($response->streamedContent())->toContain('[preflight] ▸ Pre-flight checks')->toContain('✓ /opt/srv/demo is ready for deploys.');
});

it('cancels a running run from its page', function () {
    $run = Deployment::factory()->create(['status' => DeploymentStatus::Running]);

    Livewire::test(Show::class, ['deployment' => $run])
        ->assertSee('Cancel')
        ->call('cancel')
        ->assertSee('Cancelling…');

    expect($run->refresh()->cancel_requested_at)->not->toBeNull();
});

it('lists runs, newest first, with filters', function () {
    $ok = finishedRun();
    $failed = Deployment::factory()->create(['status' => DeploymentStatus::Failed]);

    $this->get('/deployments')->assertOk()->assertSeeInOrder([$failed->number(), $ok->number()]);
    $this->get('/deployments?status=failed')->assertOk()->assertSee($failed->number())->assertDontSee($ok->number().'<');
});

it('invites you to prepare something when there are no runs', function () {
    $this->get('/deployments')->assertOk()->assertSee('No runs yet');
});
