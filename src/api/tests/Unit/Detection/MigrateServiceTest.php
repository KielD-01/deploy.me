<?php

use App\Platform\Detection\ComposeAnalyzer;
use App\Platform\Detection\Detection;

it('notes the services with a fixed container name', function () {
    $compose = (new ComposeAnalyzer)->analyze('compose.prod.yaml', "services:\n  app-blue:\n    image: ghcr.io/o/app:\${IMAGE_TAG}\n    container_name: app-blue\n  worker:\n    image: ghcr.io/o/app:\${IMAGE_TAG}\n");

    expect($compose['fixed_names'])->toBe(['app-blue']);
});

it('migrates in a service compose run can start, not one with a fixed container name', function () {
    $detection = Detection::from([
        'framework' => ['primary' => ['name' => 'Laravel']],
        'compose' => [['file' => 'compose.prod.yaml', 'app_services' => ['app-blue', 'app-green', 'worker'], 'fixed_names' => ['app-blue', 'app-green']]],
    ]);

    expect($detection->suggestedMigrateService())->toBe('worker');
});
