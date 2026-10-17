<?php

use App\Deploy\Delivery\ImagePlan;
use App\Models\Project;

/**
 * KielD-01/warehouse: its compose file names the images `${REGISTRY}/…`, and
 * its workflow pushes them to ghcr.io/{owner}/….
 *
 * @param  list<array{var: string, kind: string, image: ?string}>  $pins
 * @param  list<array{image: string, tag: string}>  $images
 */
function registryVariableProject(array $pins, array $images): Project
{
    return Project::factory()->make([
        'github_owner' => 'KielD-01', 'github_repo' => 'warehouse', 'compose_files' => ['docker-compose.prod.yml'],
        'detection' => [
            'workflows' => [['file' => 'ci.yml', 'triggers' => ['push' => ['main']], 'images' => $images]],
            'compose' => [['file' => 'docker-compose.prod.yml', 'pins' => $pins]],
        ],
    ]);
}

it('looks for an image the compose file starts with a variable where the workflow pushes it', function () {
    $sha = str_repeat('a1b2c3d4', 5);

    $plan = ImagePlan::for(registryVariableProject(
        [['var' => 'IMAGE_TAG', 'kind' => 'tag', 'image' => '${registry:?set registry in .env}/warehouse-api']],
        [['image' => 'ghcr.io/{owner}/warehouse-api', 'tag' => '{sha}']],
    ), $sha);

    expect($plan?->pins)->toBe(['IMAGE_TAG' => $sha])
        ->and($plan->images)->toBe([['image' => 'ghcr.io/kield-01/warehouse-api', 'tag' => $sha]]);
});

it('matches each such image to its own workflow template by name', function () {
    $sha = str_repeat('0123456789', 4);

    $plan = ImagePlan::for(registryVariableProject(
        [
            ['var' => 'API_TAG', 'kind' => 'tag', 'image' => '${registry}/warehouse-api'],
            ['var' => 'WEB_TAG', 'kind' => 'tag', 'image' => '${registry}/warehouse-web'],
        ],
        [
            ['image' => 'ghcr.io/{owner}/{repo}-web', 'tag' => 'sha-{sha7}'],
            ['image' => 'ghcr.io/{owner}/{repo}-api', 'tag' => '{sha}'],
        ],
    ), $sha);

    expect($plan?->pins)->toBe(['API_TAG' => $sha, 'WEB_TAG' => 'sha-0123456'])
        ->and(array_column($plan->images, 'image'))->toBe(['ghcr.io/kield-01/warehouse-api', 'ghcr.io/kield-01/warehouse-web']);
});

it('seeds editable templates from detection, placeholders and all', function () {
    $project = registryVariableProject(
        [['var' => 'IMAGE_TAG', 'kind' => 'tag', 'image' => '${registry}/warehouse-api']],
        [['image' => 'ghcr.io/{owner}/{repo}-{service}', 'tag' => 'sha-{sha7}']],
    );

    expect($project->imageTemplates())->toBe([
        ['var' => 'IMAGE_TAG', 'kind' => 'tag', 'image' => 'ghcr.io/kield-01/warehouse-api', 'tag' => 'sha-{sha7}'],
    ]);
});

it('pins a release to the project’s own templates when detection read the workflow badly', function () {
    $sha = str_repeat('a1b2c3d4', 5);
    // Detection sees nothing useful: the workflow names its images itself.
    $project = registryVariableProject([], []);
    $project->image_templates = [
        ['var' => 'IMAGE_TAG', 'kind' => 'tag', 'image' => 'ghcr.io/{owner}/warehouse', 'tag' => 'build-{sha7}'],
        ['var' => 'WEB_IMAGE', 'kind' => 'image', 'image' => 'ghcr.io/kield-01/warehouse-web', 'tag' => '{sha}'],
    ];

    expect($project->imageTemplates())->toHaveCount(2)
        ->and(ImagePlan::for($project, $sha)?->pins)->toBe([
            'IMAGE_TAG' => 'build-a1b2c3d',
            'WEB_IMAGE' => "ghcr.io/kield-01/warehouse-web:{$sha}",
        ])
        ->and(array_column(ImagePlan::for($project, $sha)->images, 'image'))
        ->toBe(['ghcr.io/kield-01/warehouse', 'ghcr.io/kield-01/warehouse-web']);
});

it('drops image templates that name nothing usable', function () {
    expect(Project::normalizeImageTemplates([
        ['var' => 'IMAGE_TAG', 'kind' => 'tag', 'image' => 'ghcr.io/o/r', 'tag' => 'sha-{sha7}'],
        // The same variable twice: the first wins.
        ['var' => 'IMAGE_TAG', 'kind' => 'image', 'image' => 'ghcr.io/o/other', 'tag' => 'latest'],
        ['var' => '2BAD', 'kind' => 'tag', 'image' => 'ghcr.io/o/r', 'tag' => 'x'],
        ['var' => 'NO_IMAGE', 'kind' => 'tag', 'image' => ' ', 'tag' => 'x'],
        ['var' => 'NO_TAG', 'kind' => 'tag', 'image' => 'ghcr.io/o/r', 'tag' => ''],
        'not a row',
    ]))->toBe([['var' => 'IMAGE_TAG', 'kind' => 'tag', 'image' => 'ghcr.io/o/r', 'tag' => 'sha-{sha7}']]);
});
