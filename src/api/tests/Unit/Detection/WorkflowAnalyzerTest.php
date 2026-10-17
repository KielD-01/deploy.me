<?php

use App\Platform\Detection\WorkflowAnalyzer;

it('finds a commit-tagged GHCR image named in a step output, and the job that deploys over SSH', function () {
    $workflow = (new WorkflowAnalyzer)->analyze('deploy.yml', fixtureText('workflows/ghcr-sha.yml'));

    expect($workflow['error'])->toBeNull()
        ->and($workflow['name'])->toBe('Deploy')
        ->and($workflow['triggers'])->toBe(['push' => ['main', 'master'], 'pull_request' => false, 'manual' => false, 'schedule' => false])
        ->and($workflow['jobs'])->toBe(2)
        ->and($workflow['images'])->toBe([['image' => 'ghcr.io/{owner}/{repo}', 'tag' => '{sha}', 'count' => 1]])
        ->and($workflow['deploys'])->toBe(['via' => 'ssh', 'script' => 'deploy.sh', 'path' => '/opt/example.app']);
});

it('counts one image per matrix entry and follows outputs set in a run script', function () {
    $workflow = (new WorkflowAnalyzer)->analyze('ci-cd.yml', fixtureText('workflows/matrix-sha7.yml'));

    expect($workflow['triggers'])->toBe(['push' => ['main'], 'pull_request' => true, 'manual' => false, 'schedule' => false])
        ->and($workflow['images'])->toBe([['image' => 'ghcr.io/{owner}/example-{service}', 'tag' => 'sha-{sha7}', 'count' => 3]])
        ->and($workflow['deploys'])->toBe(['via' => 'ssh', 'script' => 'scripts/deploy-prod.sh', 'path' => null]);
});

it('follows an image name kept in env to a docker push in a run step', function () {
    $workflow = (new WorkflowAnalyzer)->analyze('ci.yml', fixtureText('workflows/env-image.yml'));

    expect($workflow['images'])->toBe([['image' => 'ghcr.io/{owner}/example-api', 'tag' => '{sha}', 'count' => 1]])
        ->and($workflow['deploys'])->toBeNull();
});

it('follows an image name copied into a shell variable and lowercased', function () {
    $workflow = (new WorkflowAnalyzer)->analyze('ci.yml', fixtureText('workflows/env-image-lowercased.yml'));

    /* `image="${IMAGE,,}"` then `docker push "$image:${GITHUB_SHA}"`. Without
       following the copy the workflow reads as publishing nothing, and a project
       whose CI does publish images is told no workflow does. */
    expect($workflow['images'])->toBe([['image' => 'ghcr.io/{owner}/example-api', 'tag' => '{sha}', 'count' => 1]]);
});

it('reads sha tags from docker/metadata-action', function () {
    $workflow = (new WorkflowAnalyzer)->analyze('release.yml', fixtureText('workflows/metadata.yml'));

    expect($workflow['triggers']['push'])->toBe(['*'])
        ->and($workflow['images'])->toBe([['image' => 'ghcr.io/{owner}/{repo}', 'tag' => 'sha-{sha7}', 'count' => 1]]);
});

it('notices a manual workflow that deploys on the server', function () {
    $workflow = (new WorkflowAnalyzer)->analyze('deploy.yml', fixtureText('workflows/manual-deploy.yml'));

    expect($workflow['triggers']['push'])->toBeNull()
        ->and($workflow['triggers']['manual'])->toBeTrue()
        ->and($workflow['images'])->toBe([])
        ->and($workflow['deploys'])->toBe(['via' => 'ssh', 'script' => 'deploy/deploy.sh', 'path' => '/srv/projects/example']);
});

it('reports a workflow it can’t read instead of failing', function () {
    $workflow = (new WorkflowAnalyzer)->analyze('broken.yml', "on: [push\njobs: {");

    expect($workflow['error'])->toStartWith('Not valid YAML')
        ->and($workflow['images'])->toBe([]);
});
