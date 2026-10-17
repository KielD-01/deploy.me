<?php

use App\Platform\Detection\ComposeAnalyzer;
use App\Platform\Detection\Detection;

it('leaves a service behind a profile out of the ones a deploy recreates, and notes what each service runs', function () {
    $compose = (new ComposeAnalyzer)->analyze('docker-compose.prod.yml', <<<'YAML'
        services:
          shop-api:
            image: ghcr.io/o/shop-api:${IMAGE_TAG}
            command: [php, artisan, octane:start]
          shop-migrate:
            image: ghcr.io/o/shop-api:${IMAGE_TAG}
            profiles: [deploy]
            command: ["php", "artisan", "migrate", "--force", "--database=pgsql_direct"]
          shop-restore:
            image: timescale/timescaledb:2.29.0-pg18
            profiles: [restore]
        YAML);

    expect($compose['app_services'])->toBe(['shop-api'])
        ->and($compose['commands'])->toBe([
            'shop-api' => 'php artisan octane:start',
            'shop-migrate' => 'php artisan migrate --force --database=pgsql_direct',
        ]);
});

it('suggests the dedicated migrate service, even behind a profile', function () {
    $compose = (new ComposeAnalyzer)->analyze('docker-compose.prod.yml', <<<'YAML'
        services:
          shop-api:
            image: ghcr.io/o/shop-api:${IMAGE_TAG}
          shop-migrate:
            image: ghcr.io/o/shop-api:${IMAGE_TAG}
            profiles: [deploy]
            command: php artisan migrate --force
        YAML);

    $detection = Detection::from(['framework' => ['primary' => ['name' => 'Laravel']], 'compose' => [$compose]]);

    expect($detection->suggestedMigrateService())->toBe('shop-migrate')
        ->and($detection->suggestedAppServices())->toBe(['shop-api']);
});

it('notes how long compose waits for each service to stop', function () {
    $compose = (new ComposeAnalyzer)->analyze('docker-compose.prod.yml', <<<'YAML'
        x-worker: &worker
          image: ghcr.io/o/shop-api:${IMAGE_TAG}
          stop_grace_period: 2m
        services:
          shop-api:
            image: ghcr.io/o/shop-api:${IMAGE_TAG}
          shop-horizon:
            <<: *worker
          shop-ingest:
            <<: *worker
            stop_grace_period: 1900s
          shop-reverb:
            image: ghcr.io/o/shop-api:${IMAGE_TAG}
            stop_grace_period: 1m30.5s
        YAML);

    expect($compose['stop_grace'])->toBe(['shop-horizon' => 120, 'shop-ingest' => 1900, 'shop-reverb' => 91]);
});

it('tells which services the edge routes to and which say when they are ready', function () {
    $compose = (new ComposeAnalyzer)->analyze('docker-compose.prod.yml', <<<'YAML'
        services:
          shop-api:
            image: ghcr.io/o/shop-api:${IMAGE_TAG}
            labels:
              caddy: shop.example
              caddy.reverse_proxy: "{{upstreams 8000}}"
            healthcheck:
              test: ["CMD-SHELL", "curl -fsS http://127.0.0.1:8000/up || exit 1"]
          shop-horizon:
            image: ghcr.io/o/shop-api:${IMAGE_TAG}
            healthcheck:
              disable: true
          shop-meilisearch:
            image: getmeili/meilisearch:v1.53
            healthcheck:
              test: ["CMD-SHELL", "curl -fsS http://127.0.0.1:7700/health || exit 1"]
        YAML);

    expect($compose['edge_services'])->toBe(['shop-api'])
        ->and($compose['healthchecked'])->toBe(['shop-api', 'shop-meilisearch']);
});
