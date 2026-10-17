<?php

use App\Deploy\RemoteLayout;

it('lays out releases, shared files and the current link', function () {
    $layout = new RemoteLayout('/opt/srv/demo', versionable: true);

    expect($layout->lock())->toBe('/opt/srv/demo/.deploy/lock')
        ->and($layout->stateFile())->toBe('/opt/srv/demo/.deploy/state.json')
        ->and($layout->runs())->toBe('/opt/srv/demo/.deploy/runs')
        ->and($layout->release('20260910-143012'))->toBe('/opt/srv/demo/releases/20260910-143012')
        ->and($layout->current())->toBe('/opt/srv/demo/current')
        ->and($layout->envFile('src/api/.env'))->toBe('/opt/srv/demo/shared/src/api/.env')
        ->and(array_column($layout->preview(5, ['.env', 'prod.env']), 'note'))->toBe(['5 kept', '.env, prod.env', 'the live release']);
});

it('keeps env files in the project folder when it deploys in place', function () {
    $layout = new RemoteLayout('/srv/apps/demo', versionable: false);

    expect($layout->envFile('.env'))->toBe('/srv/apps/demo/.env')
        ->and($layout->deployDir())->toBe('/srv/apps/demo/.deploy')
        ->and($layout->incoming('20260910-143012'))->toBe('/srv/apps/demo/.deploy/incoming/20260910-143012');
});
