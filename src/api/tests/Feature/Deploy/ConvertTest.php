<?php

use App\Deploy\DeployContext;
use App\Deploy\Pipeline\PipelineRunner;
use App\Deploy\Pipeline\Steps\Preflight;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Enums\ReleaseStatus;
use App\Events\DeploymentLogged;
use App\Events\DeploymentUpdated;
use App\Models\Deployment;
use App\Models\Release;
use App\Models\Target;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshResult;
use App\Platform\Ssh\Testing\FakeSshClient;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->ssh = fakeConvertScripts(fakeDeployScripts(new FakeSshClient));
    $this->app->instance(SshClient::class, $this->ssh);
    Event::fake([DeploymentUpdated::class, DeploymentLogged::class]);
});

/**
 * @return array<string, mixed>
 */
function conversionOptions(array $overrides = []): array
{
    return ['conversion' => [
        'items' => [
            ['path' => 'src', 'type' => 'd', 'to' => 'release'],
            ['path' => 'src/api/.env', 'type' => 'f', 'to' => 'shared'],
            ['path' => 'docker-compose.prod.yml', 'type' => 'f', 'to' => 'release'],
            ['path' => '.env', 'type' => 'f', 'to' => 'shared'],
        ],
        'stays' => ['.env.save'],
        'commit' => str_repeat('c', 40),
        'message' => 'fix(web): stop the air-raid bar repainting',
        'restart' => false,
        'shared_paths' => ['src/api/.env', '.env'],
        'shared_paths_before' => null,
        ...$overrides,
    ]];
}

function convertRun(Target $target, DeploymentKind $kind = DeploymentKind::Convert, array $options = []): Deployment
{
    $deployment = Deployment::factory()->create(['target_id' => $target->id, 'kind' => $kind, 'options' => $options]);
    app(PipelineRunner::class)->run($deployment);

    return $deployment->refresh();
}

/**
 * @return array<string, string>
 */
function convertSteps(Deployment $deployment): array
{
    return $deployment->steps()->get()->mapWithKeys(fn ($step) => [$step->key => $step->status->value])->all();
}

/**
 * The modes deploy-convert.sh ran in, in order.
 *
 * @return list<?string>
 */
function convertModes(FakeSshClient $ssh): array
{
    return collect($ssh->calls)->filter(fn (array $call) => $call['script']->name === 'deploy-convert')
        ->map(fn (array $call) => scriptVar($call['script'], 'MODE'))->values()->all();
}

it('converts a folder deployed in place into a first release', function () {
    $target = conversionTarget();

    $run = convertRun($target, options: conversionOptions());
    $release = $run->release;

    expect($run->status)->toBe(DeploymentStatus::Succeeded, (string) $run->outcome)
        ->and(convertSteps($run))->toBe([
            'preflight' => 'succeeded', 'lease' => 'succeeded', 'convert_check' => 'succeeded', 'convert_backup' => 'succeeded',
            'convert_move' => 'succeeded', 'convert_link' => 'succeeded', 'convert_compose' => 'succeeded', 'activate' => 'succeeded',
            'convert_restart' => 'skipped', 'state' => 'succeeded', 'convert_undo' => 'skipped', 'unlock' => 'succeeded',
        ])
        ->and(convertModes($this->ssh))->toBe(['check', 'backup', 'move', 'link', 'compose'])
        ->and($run->outcome)->toBe("/opt/srv/demo deploys into releases/ now: 2 items moved into release {$release->name} and 2 into shared/.")
        ->and($release->layout)->toBe(Release::IN_RELEASES)
        ->and($release->status)->toBe(ReleaseStatus::Ready)
        ->and($release->path)->toBe("/opt/srv/demo/releases/{$release->name}")
        ->and($release->commit_sha)->toBe(str_repeat('c', 40))
        ->and($run->steps()->where('key', 'convert_move')->value('detail'))->toBe("2 into releases/{$release->name}, 2 into shared/")
        ->and($run->options['backup'])->toBe(".deploy/convert-{$release->name}/old")
        ->and($target->refresh()->current_release_id)->toBe($release->id)
        ->and($target->last_deployed_at)->toBeNull()
        ->and($target->needsConversion())->toBeFalse()
        ->and($target->project->refresh()->shared_paths)->toBe(['src/api/.env', '.env']);

    $move = collect($this->ssh->calls)->first(fn (array $call) => scriptVar($call['script'], 'MODE') === 'move');
    expect(scriptVar($move['script'], 'ITEMS'))->toBe("release\td\tsrc\nshared\tf\tsrc/api/.env\nrelease\tf\tdocker-compose.prod.yml\nshared\tf\t.env")
        ->and(scriptVar($move['script'], 'STAYS'))->toBe('.env.save')
        ->and(scriptVar($move['script'], 'RELEASE'))->toBe($release->name);

    // The deploy script of the project doesn't run: converting only switches current.
    $this->ssh->assertRan('box', 'deploy-activate');
    expect(collect($this->ssh->calls)->contains(fn (array $call) => $call['script']->name === 'deploy-run-script'))->toBeFalse();
});

it('puts every item back when a step fails after the moves', function () {
    fakeConvertScripts($this->ssh, ['link' => new SshResult(14, "✗ releases/x/.env is in the way\n", '', 0.1)]);
    $target = conversionTarget();

    $run = convertRun($target, options: conversionOptions());

    expect($run->status)->toBe(DeploymentStatus::Failed)
        ->and($run->outcome)->toBe('releases/x/.env is in the way. Every item is back where it was.')
        ->and(convertModes($this->ssh))->toBe(['check', 'backup', 'move', 'link', 'undo'])
        ->and(convertSteps($run))->toMatchArray(['convert_link' => 'failed', 'activate' => 'skipped', 'convert_undo' => 'succeeded', 'unlock' => 'succeeded'])
        ->and($run->release->status)->toBe(ReleaseStatus::Failed)
        ->and($target->refresh()->current_release_id)->toBeNull()
        ->and($target->project->refresh()->shared_paths)->toBeNull();
});

it('restarts the stack from the release when asked, and from the old folder once it’s put back', function () {
    fakeDeployScripts($this->ssh, ['deploy-compose-health' => new SshResult(30, "✗ After 10 s: api (running, unhealthy)\n::deployme::waiting=api (running, unhealthy)\n", '', 1)]);
    $target = conversionTarget();

    $run = convertRun($target, options: conversionOptions(['restart' => true]));
    $recreated = collect($this->ssh->calls)->filter(fn (array $call) => $call['script']->name === 'deploy-compose')->map(fn (array $call) => scriptVar($call['script'], 'DIR'))->values()->all();

    expect($run->status)->toBe(DeploymentStatus::Failed)
        ->and($run->outcome)->toStartWith('Health check failed')
        ->and($run->outcome)->toEndWith('Every item is back where it was.')
        ->and($recreated)->toBe([$run->release->path, '/opt/srv/demo'])
        ->and($run->steps()->where('key', 'convert_undo')->value('detail'))->toBe('4 items back · app services recreated from the old folder');
});

it('moves nothing when the folder changed since it was read', function () {
    fakeConvertScripts($this->ssh, ['check' => new SshResult(8, "✗ notes.txt is new since the folder was read. Read it again.\n", '', 0.1)]);

    $run = convertRun(conversionTarget(), options: conversionOptions());

    expect($run->status)->toBe(DeploymentStatus::Failed)
        ->and($run->outcome)->toBe('notes.txt is new since the folder was read. Read it again.')
        ->and(convertModes($this->ssh))->toBe(['check'])
        ->and(Release::query()->count())->toBe(0);
});

it('refuses a plan that would move something outside the folder', function () {
    $run = convertRun(conversionTarget(), options: conversionOptions(['items' => [['path' => '../etc', 'type' => 'd', 'to' => 'release']]]));

    expect($run->status)->toBe(DeploymentStatus::Failed)
        ->and($run->outcome)->toBe('The plan has an item deploy.me can’t move: ../etc.')
        ->and(convertModes($this->ssh))->toBe([]);
});

it('undoes a conversion while its release is live', function () {
    $target = conversionTarget();
    $release = convertRun($target, options: conversionOptions())->release;

    $undo = convertRun($target->refresh(), DeploymentKind::UndoConvert, ['release_id' => $release->id]);

    expect($undo->status)->toBe(DeploymentStatus::Succeeded, (string) $undo->outcome)
        ->and($undo->outcome)->toBe('/opt/srv/demo is back the way it was before the conversion.')
        ->and(convertSteps($undo))->toBe(['preflight' => 'succeeded', 'lease' => 'succeeded', 'convert_undo' => 'succeeded', 'state' => 'succeeded', 'unlock' => 'succeeded'])
        ->and(convertModes($this->ssh))->toBe(['check', 'backup', 'move', 'link', 'compose', 'undo'])
        ->and($release->refresh()->status)->toBe(ReleaseStatus::Pruned)
        ->and($target->refresh()->current_release_id)->toBeNull()
        ->and($target->project->refresh()->shared_paths)->toBeNull();
});

it('won’t undo a conversion once another release is live', function () {
    $target = conversionTarget();
    $converted = convertRun($target, options: conversionOptions())->release;
    $later = Release::factory()->create(['target_id' => $target->id, 'name' => '20990101-000000', 'layout' => Release::IN_RELEASES]);
    $target->update(['current_release_id' => $later->id]);

    $undo = convertRun($target, DeploymentKind::UndoConvert, ['release_id' => $converted->id]);

    expect($undo->status)->toBe(DeploymentStatus::Failed)
        ->and($undo->outcome)->toBe("Release {$converted->name} isn’t live any more, so the conversion can’t be undone.");
});

it('won’t deploy into a folder an earlier deployment still runs from', function () {
    config(['deployme.project_roots' => [sys_get_temp_dir()]]);
    $target = demoTarget([
        'source' => 'local', 'local_path' => tempGitRepo(['index.php' => '<?php']), 'github_owner' => null, 'github_repo' => null,
        'delivery_strategies' => [['key' => 'push_archive', 'enabled' => true]],
    ]);
    fakeDeployScripts($this->ssh, ['deploy-preflight' => FakeSshClient::ok(
        "::deployme::foreign=.env|compose.prod.yaml|deploy.sh|.env.save|\n::deployme::foreign_count=4\n::deployme::free=104857600000\n::deployme::ok=1\n"
    )]);

    $run = convertRun($target, DeploymentKind::Deploy);

    expect($run->status)->toBe(DeploymentStatus::Failed)
        ->and($run->outcome)->toBe('/opt/srv/demo still holds an earlier deployment (4 items). Convert it to releases first: it’s on the project’s Targets tab.')
        ->and($target->refresh()->needsConversion())->toBeTrue()
        ->and($target->leftoverCount())->toBe(4)
        ->and(collect($this->ssh->calls)->contains(fn (array $call) => $call['script']->name === 'deploy-bootstrap'))->toBeFalse();
});

it('deploys once the folder is converted, whatever else stays in it', function () {
    $target = demoTarget();
    $release = Release::factory()->create(['target_id' => $target->id, 'layout' => Release::IN_RELEASES, 'status' => ReleaseStatus::Ready]);
    $target->update(['current_release_id' => $release->id]);
    fakeDeployScripts($this->ssh, ['deploy-preflight' => FakeSshClient::ok("::deployme::foreign=.env.save|\n::deployme::foreign_count=1\n::deployme::free=104857600000\n::deployme::ok=1\n")]);
    $deployment = Deployment::factory()->create(['target_id' => $target->id, 'kind' => DeploymentKind::Deploy]);

    expect((new Preflight)->run(DeployContext::for($deployment, $this->ssh)))->toContain('existing files kept');
});
