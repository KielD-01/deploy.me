<?php

use App\Platform\Detection\ComposeAnalyzer;

it('reads a blue/green compose file with anchors, merge keys and custom tags', function () {
    $compose = (new ComposeAnalyzer)->analyze('compose.prod.yaml', fixtureText('compose/blue-green.yaml'));

    expect($compose['error'])->toBeNull()
        ->and($compose['name'])->toBe('example')
        ->and($compose['services'])->toBe(['app-blue', 'app-green', 'worker', 'meilisearch'])
        ->and($compose['app_services'])->toBe(['app-blue', 'app-green', 'worker'])
        ->and($compose['pins'])->toBe([['var' => 'IMAGE_TAG', 'kind' => 'tag', 'image' => 'ghcr.io/example-org/example.app']])
        ->and($compose['ghcr'])->toBe(['ghcr.io/example-org/example.app'])
        ->and($compose['proxy_network'])->toBeTrue()
        ->and($compose['external_networks'])->toBe(['proxy-network'])
        ->and($compose['domains'])->toBe(['example.app', 'admin.example.app'])
        ->and($compose['env_files'])->toBe(['.env']);
});

it('treats a variable holding the whole image as a pin, and its default as the image', function () {
    $compose = (new ComposeAnalyzer)->analyze('docker-compose.prod.yml', fixtureText('compose/image-vars.yml'));

    expect($compose['app_services'])->toBe(['api', 'web', 'migrate'])
        ->and($compose['pins'])->toBe([
            ['var' => 'API_IMAGE', 'kind' => 'image', 'image' => 'ghcr.io/example-org/studio-api'],
            ['var' => 'WEB_IMAGE', 'kind' => 'image', 'image' => 'ghcr.io/example-org/studio-web'],
        ])
        ->and($compose['domains'])->toBe(['studio.example.com'])
        ->and($compose['proxy_network'])->toBeFalse();
});

it('recreates what runs the built image or serves files from the folder, not a data store keeping its data there', function () {
    $compose = (new ComposeAnalyzer)->analyze('deploy/compose.yaml', <<<'YAML'
        services:
          web:
            image: nginx:alpine
            volumes:
              - ./html5-game:/usr/share/nginx/html:ro
          api:
            build: ./backend
            image: bm-api:latest
          reverb:
            image: bm-api:latest
            command: [php, artisan, reverb:start]
          search:
            image: getmeili/meilisearch:v1.13
            volumes:
              - ./meili:/meili_data
          mail:
            image: axllent/mailpit
        YAML);

    expect($compose['app_services'])->toBe(['web', 'api', 'reverb']);
});

it('splits image references without tripping over variables, ports or digests', function (string $image, string $repository, ?string $tag) {
    expect(ComposeAnalyzer::splitImage($image))->toBe([$repository, $tag]);
})->with([
    ['ghcr.io/org/app:${IMAGE_TAG:-latest}', 'ghcr.io/org/app', '${IMAGE_TAG:-latest}'],
    ['localhost:5000/app:1.2', 'localhost:5000/app', '1.2'],
    ['${REGISTRY}/api:${TAG}', '${REGISTRY}/api', '${TAG}'],
    ['postgres', 'postgres', null],
    ['nginx@sha256:abc', 'nginx', null],
]);

it('reports a compose file it can’t read', function () {
    expect((new ComposeAnalyzer)->analyze('compose.yaml', "services:\n  app: [")['error'])->toStartWith('Not valid YAML');
});

it('lists the networks Compose expects to find on the server, not the ones it creates', function () {
    $compose = (new ComposeAnalyzer)->analyze('compose.yaml', <<<'YAML'
        networks:
          edge:
            external: true
            name: proxy-network
          legacy:
            external:
              name: old-network
          tenant:
            external: true
            name: ${TENANT}-network
          backend:
            driver: bridge
          bare:
        services:
          app:
            image: app:latest
        YAML);

    // A name a variable fills in can't be checked, so it isn't listed.
    expect($compose['external_networks'])->toBe(['proxy-network', 'old-network'])
        ->and($compose['error'])->toBeNull();
});
