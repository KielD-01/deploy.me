<?php

use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Jobs\ReadTargetFolder;
use App\Jobs\RunDeployment;
use App\Livewire\Deployments\Show as RunPage;
use App\Livewire\Projects\Convert;
use App\Livewire\Projects\DeployDialog;
use App\Livewire\Projects\Targets;
use App\Models\Deployment;
use App\Models\Release;
use App\Models\User;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\Testing\FakeSshClient;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->actingAs(User::factory()->create());
    // Runs and reads stay queued: nothing here reaches a server.
    Queue::fake();
    $this->ssh = new FakeSshClient;
    $this->app->instance(SshClient::class, $this->ssh);
});

it('reads the folder when the page opens', function () {
    $target = conversionTarget();

    Livewire::test(Convert::class, ['project' => $target->project, 'target' => $target])
        ->assertSee('Reading /opt/srv/demo on');

    Queue::assertPushed(ReadTargetFolder::class, fn (ReadTargetFolder $job) => $job->targetId === $target->id && $job->compare);
    expect($target->refresh()->folder_scan['reading'])->not->toBeNull();
});

it('shows the plan and starts the conversion as you picked it', function () {
    $target = conversionTarget();
    $target->update(['folder_scan' => [...conversionScan(), 'read_at' => now()->toIso8601String()], 'folder_scanned_at' => now()]);

    $page = Livewire::test(Convert::class, ['project' => $target->project, 'target' => $target])
        ->assertSee(['What goes where', 'src/api/.env', 'Env file of api and queue', 'looks like an old copy of .env', 'every file matches commit', 'ccccccc'])
        ->assertSee(['Leave it running', 'Compose resolves with src/api/.env and .env linked from shared/', 'Convert /opt/srv/demo'])
        ->call('pick', 'design', 'stays')
        ->call('convert');

    Queue::assertNotPushed(ReadTargetFolder::class);
    $run = Deployment::query()->latest('id')->firstOrFail();

    expect($run->kind)->toBe(DeploymentKind::Convert)
        ->and($run->options['conversion']['stays'])->toBe(['design', '.env.save'])
        ->and(collect($run->options['conversion']['items'])->pluck('path')->all())->toBe(['src', 'src/api/.env', 'docker-compose.prod.yml', '.env'])
        ->and($run->options['conversion']['restart'])->toBeFalse();

    $page->assertRedirect(route('deployments.show', $run));
    Queue::assertPushed(RunDeployment::class, fn (RunDeployment $job) => $job->deploymentId === $run->id);
});

it('won’t convert while a check fails', function () {
    $target = conversionTarget(['detection' => ['workflows' => [[
        'file' => '.github/workflows/deploy.yml', 'name' => 'Deploy', 'triggers' => ['push' => ['main']],
        'deploys' => ['via' => 'ssh', 'script' => 'deploy.sh', 'path' => '/opt/srv/demo'],
    ]]]]);
    $target->update(['folder_scan' => [...conversionScan(), 'read_at' => now()->toIso8601String()]]);

    Livewire::test(Convert::class, ['project' => $target->project, 'target' => $target])
        ->assertSee(['GitHub Actions deploys into this folder', 'deploy.yml', 'Fix the failed checks first.'])
        ->call('convert')
        ->assertSee('Fix the failed checks first.');

    expect(Deployment::query()->count())->toBe(0);
});

it('tells you on the Targets tab when a folder needs converting', function () {
    $target = conversionTarget();
    $target->update(['folder_scan' => ['foreign' => ['count' => 4, 'names' => ['.env'], 'seen_at' => now()->toIso8601String()]]]);

    Livewire::test(Targets::class, ['projectId' => $target->project_id])
        ->assertSee(['An earlier deployment still runs from /opt/srv/demo', 'Not converted · 4 items', 'Review the conversion'])
        ->assertSee(route('projects.convert', [$target->project, $target]));

    Queue::assertNothingPushed();
});

it('won’t deploy to a folder that needs converting', function () {
    config(['deployme.project_roots' => [sys_get_temp_dir()]]);
    $target = conversionTarget(['source' => 'local', 'local_path' => tempGitRepo(['index.php' => '<?php']), 'github_owner' => null, 'github_repo' => null]);
    $target->update(['folder_scan' => ['foreign' => ['count' => 4, 'names' => [], 'seen_at' => now()->toIso8601String()]]]);

    Livewire::test(DeployDialog::class, ['projectId' => $target->project_id])
        ->call('openFor', $target->id)
        ->call('deploy')
        ->assertSee('still runs from an earlier deployment. Convert that folder first');

    Queue::assertNotPushed(RunDeployment::class);
});

it('offers to undo a conversion, and to remove its backup once you confirm with a passkey', function () {
    $target = conversionTarget();
    $run = Deployment::factory()->create([
        'target_id' => $target->id, 'kind' => DeploymentKind::Convert, 'status' => DeploymentStatus::Succeeded,
        'options' => ['backup' => '.deploy/convert-20260911-120000/old'],
    ]);
    $release = Release::factory()->create(['target_id' => $target->id, 'deployment_id' => $run->id, 'name' => '20260911-120000', 'layout' => Release::IN_RELEASES]);
    $run->update(['release_id' => $release->id]);
    $target->update(['current_release_id' => $release->id]);

    Livewire::test(RunPage::class, ['deployment' => $run->refresh()])
        ->assertSee(['Undo conversion', '.deploy/convert-20260911-120000/old', 'Remove backup'])
        ->call('removeBackup')
        ->assertRedirect(route('password.confirm'));
    $this->ssh->assertNothingRan();

    session(['auth.password_confirmed_at' => time()]);
    $this->ssh->on('box', 'deploy-convert', FakeSshClient::ok("✓ Removed .deploy/convert-20260911-120000/old\n"));

    Livewire::test(RunPage::class, ['deployment' => $run->refresh()])
        ->call('removeBackup')
        ->assertSee('Removed .deploy/convert-20260911-120000/old.')
        ->assertDontSee('Remove backup');

    expect(scriptVar($this->ssh->calls[0]['script'], 'MODE'))->toBe('drop-backup')
        ->and(scriptVar($this->ssh->calls[0]['script'], 'RELEASE'))->toBe('20260911-120000')
        ->and($run->refresh()->options['backup_removed_at'])->not->toBeNull();

    Livewire::test(RunPage::class, ['deployment' => $run->refresh()])->call('undoConversion');
    $undo = Deployment::query()->latest('id')->firstOrFail();

    expect($undo->kind)->toBe(DeploymentKind::UndoConvert)
        ->and($undo->options['release_id'])->toBe($release->id);
});
