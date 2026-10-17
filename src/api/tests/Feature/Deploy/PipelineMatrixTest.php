<?php

use App\Deploy\Compose;
use App\Deploy\Delivery\DeliveryResolver;
use App\Deploy\Delivery\ImagePlan;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\PipelineFactory;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\Steps\WritePins;
use App\Enums\DeliveryStrategyKey;
use App\Enums\DeploymentKind;
use App\Models\Credential;
use App\Models\Deployment;
use App\Models\ProjectEnvFile;
use App\Models\Release;
use App\Models\Target;
use App\Platform\Ssh\Testing\FakeSshClient;

/**
 * A project in one of the four deploy modes, with an env file and one image
 * pin in its compose file, and nothing else switched on: no hooks, no migrate
 * service, no health check of its own, no blue/green.
 */
function modeTarget(bool $versionable, bool $dockerized): Target
{
    $target = demoTarget([
        'versionable' => $versionable,
        'dockerized' => $dockerized,
        'stack_name' => 'demo',
        'compose_files' => ['compose.yaml'],
        'app_services' => ['api'],
        'github_owner' => 'KielD-01',
        'github_repo' => 'demo',
        'shared_paths' => [],
        'detection' => [
            'workflows' => [['file' => 'ci.yml', 'images' => [['image' => 'ghcr.io/{owner}/{repo}-api', 'tag' => 'sha-{sha7}']]]],
            'compose' => [['file' => 'compose.yaml', 'pins' => [['var' => 'IMAGE_TAG', 'kind' => 'tag', 'image' => 'ghcr.io/kield-01/demo-api']]]],
        ],
    ]);

    $target->project->envFiles()->create(['path' => '.env', 'role' => 'compose', ...ProjectEnvFile::contentAttributes("APP_NAME=Demo\n")]);

    return $target;
}

/**
 * The steps a run would really do: the pipeline the factory builds, minus the
 * ones that skip themselves. The memo stands in for the steps that fill it —
 * the release, the strategy, and the pins WritePins would have written.
 *
 * @return list<string>
 */
function stepsThatRun(Target $target, DeploymentKind $kind, ?DeliveryStrategyKey $strategy): array
{
    $deployment = Deployment::factory()->create(['target_id' => $target->id, 'kind' => $kind, 'strategy' => $strategy]);
    $project = $target->project;
    $sha = str_repeat('a1b2c3d4', 5);
    $release = Release::factory()->create([
        'target_id' => $target->id, 'deployment_id' => $deployment->id, 'commit_sha' => $sha, 'strategy' => $strategy,
        'layout' => $project->versionable ? Release::IN_RELEASES : Release::IN_PLACE,
    ]);

    $pins = match (true) {
        $strategy === DeliveryStrategyKey::GhcrImage => ImagePlan::for($project, $sha)?->pins ?? [],
        Compose::builtin($project) => WritePins::builtPins($project, $release),
        default => [],
    };
    $release->update(['image_pins' => $pins ?: null]);

    $context = DeployContext::for($deployment, new FakeSshClient);
    $context->memo['release'] = $release;
    $context->memo['previous'] = null;
    $context->memo['strategy'] = $strategy;

    $main = app(PipelineFactory::class)->for($deployment)['main'];

    return collect($main)->filter(fn (Step $step) => $step->shouldRun($context))->map(fn (Step $step) => $step->key())->values()->all();
}

it('gives each kind of run its own steps', function (DeploymentKind $kind, array $main, array $cleanup) {
    $target = modeTarget(versionable: true, dockerized: true);
    $deployment = Deployment::factory()->create(['target_id' => $target->id, 'kind' => $kind]);

    $pipeline = app(PipelineFactory::class)->for($deployment);

    expect(array_map(fn (Step $step) => $step->key(), $pipeline['main']))->toBe($main)
        ->and(array_map(fn (Step $step) => $step->key(), $pipeline['cleanup']))->toBe($cleanup);
})->with([
    'bootstrap' => [DeploymentKind::Bootstrap, ['preflight', 'lease', 'folders', 'env', 'state'], ['unlock']],
    'deploy' => [DeploymentKind::Deploy, [
        'resolve', 'preflight', 'lease', 'folders', 'upload', 'shared', 'env', 'pins', 'stack', 'hooks_after_upload',
        'login', 'pull', 'build', 'backup', 'migrate', 'hooks_release', 'activate', 'roll', 'recreate', 'health',
        'hooks_after_activate', 'state', 'prune', 'prune_images',
    ], ['rollback', 'unlock']],
    'rollback' => [DeploymentKind::Rollback, [
        'pick', 'preflight', 'lease', 'login', 'restore_pins', 'pull', 'activate', 'roll', 'recreate', 'health',
        'hooks_after_activate', 'state',
    ], ['rollback', 'unlock']],
    'adopt' => [DeploymentKind::Adopt, ['preflight', 'lease', 'inspect', 'state'], ['unlock']],
    'convert' => [DeploymentKind::Convert, [
        'preflight', 'lease', 'convert_check', 'convert_backup', 'convert_move', 'convert_link', 'convert_compose',
        'activate', 'convert_restart', 'state',
    ], ['convert_undo', 'unlock']],
    'undo_convert' => [DeploymentKind::UndoConvert, ['preflight', 'lease', 'convert_undo', 'state'], ['unlock']],
    'unlock' => [DeploymentKind::Unlock, ['break_lock'], []],
]);

it('runs only the steps a deploy mode and a strategy need', function (bool $versionable, bool $dockerized, string $strategy, array $expected) {
    $target = modeTarget($versionable, $dockerized);

    expect(stepsThatRun($target, DeploymentKind::Deploy, DeliveryStrategyKey::from($strategy)))->toBe($expected);
})->with(function () {
    // The steps every deploy does, whatever the mode.
    $always = ['resolve', 'preflight', 'lease', 'folders', 'upload', 'env'];
    $rows = [];

    foreach ([false, true] as $versionable) {
        foreach ([false, true] as $dockerized) {
            foreach (DeliveryStrategyKey::cases() as $key) {
                // ghcr.io images are only ever picked for a Docker project.
                if ($key === DeliveryStrategyKey::GhcrImage && ! $dockerized) {
                    continue;
                }

                $ghcr = $key === DeliveryStrategyKey::GhcrImage;
                $steps = [
                    ...$always,
                    ...($versionable ? ['shared'] : []),
                    ...($dockerized ? ['pins', 'stack'] : []),
                    ...($ghcr ? ['login', 'pull'] : []),
                    ...($dockerized && ! $ghcr ? ['build'] : []),
                    ...($versionable ? ['activate'] : []),
                    ...($dockerized ? ['recreate', 'health'] : []),
                    'state',
                    ...($versionable ? ['prune'] : []),
                    ...($dockerized ? ['prune_images'] : []),
                ];

                // The pipeline's own order, not the order they're listed in.
                $order = ['resolve', 'preflight', 'lease', 'folders', 'upload', 'shared', 'env', 'pins', 'stack', 'login', 'pull', 'build', 'activate', 'recreate', 'health', 'state', 'prune', 'prune_images'];
                usort($steps, fn (string $a, string $b) => array_search($a, $order, true) <=> array_search($b, $order, true));

                $mode = ($versionable ? 'releases' : 'in place').($dockerized ? ' + docker' : '');
                $rows["{$mode} · {$key->value}"] = [$versionable, $dockerized, $key->value, $steps];
            }
        }
    }

    return $rows;
});

it('never offers ghcr.io images to a project that isn’t dockerized', function () {
    Credential::factory()->create();
    $project = modeTarget(versionable: true, dockerized: false)->project;
    $project->update(['delivery_strategies' => [['key' => 'ghcr_image', 'enabled' => true], ['key' => 'push_archive', 'enabled' => true]]]);

    $resolution = app(DeliveryResolver::class)->resolve($project);

    expect($resolution->trace[0])->toMatchArray(['key' => 'ghcr_image', 'state' => 'not_applicable', 'reason' => 'Only for Docker projects.'])
        ->and($resolution->strategy)->toBe(DeliveryStrategyKey::PushArchive);
});
