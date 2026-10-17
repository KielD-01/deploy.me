<?php

use App\Platform\Detection\ComposeAnalyzer;
use App\Platform\Detection\Detection;
use App\Platform\Detection\FrameworkDetector;
use App\Platform\Detection\LocalFiles;
use App\Platform\Detection\ProjectDetector;
use App\Platform\Detection\WorkflowAnalyzer;

beforeEach(function () {
    $this->detect = fn (string $dir) => new Detection(
        (new ProjectDetector(new ComposeAnalyzer, new WorkflowAnalyzer, new FrameworkDetector))->detect(new LocalFiles($dir)),
    );
});

it('finds what a deploy needs, and skips dependencies and other checkouts', function () {
    $dir = tempProject([
        'compose.yaml' => "services:\n  app:\n    build: .\n",
        'compose.prod.yaml' => fixtureText('compose/blue-green.yaml'),
        'docker-compose.local.yml' => "services:\n  app:\n    build: .\n",
        '.github/workflows/deploy.yml' => fixtureText('workflows/ghcr-sha.yml'),
        'deploy.sh' => "#!/bin/sh\n",
        'docker/app/Dockerfile' => "FROM php\n",
        'src/api/artisan' => "<?php\n",
        'src/api/composer.json' => json_encode(['require' => ['laravel/framework' => '^13.0', 'laravel/horizon' => '^5.0']]),
        'src/api/composer.lock' => json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v13.31.2']]]),
        'src/api/package.json' => json_encode(['devDependencies' => ['vite' => '^7.0']]),
        'src/api/.env.example' => "APP_NAME=Example\nAPP_KEY=\n# comment\nDB_HOST=postgres\n",
        'web/package.json' => json_encode(['dependencies' => ['react' => '^19.0'], 'devDependencies' => ['vite' => '^7.1']]),
        'node_modules/left-pad/package.json' => '{}',
        '.claude/worktrees/agent-1/.env.example' => "A=1\n",
        'checkout/.git/HEAD' => "ref: refs/heads/main\n",
        'checkout/compose.yaml' => "services: {}\n",
    ]);

    $detection = ($this->detect)($dir);

    expect(array_column($detection->composeFiles(), 'file'))->toBe(['compose.prod.yaml', 'compose.yaml', 'docker-compose.local.yml'])
        ->and($detection->framework())->toBe(['name' => 'Laravel', 'version' => '13.31', 'path' => 'src/api', 'extras' => ['Horizon']])
        ->and(array_map(fn (array $app) => $app['name'].'@'.$app['path'], $detection->apps()))->toBe(['Laravel@src/api', 'Vite@web'])
        ->and($detection->envTemplates())->toBe([['path' => 'src/api/.env.example', 'keys' => 3]])
        ->and($detection->deployScripts())->toBe(['deploy.sh'])
        ->and($detection->dockerfiles())->toBe(['docker/app/Dockerfile'])
        ->and($detection->imagePublishing())->toMatchArray(['workflow' => 'deploy.yml', 'count' => 1, 'tag' => '{sha}'])
        ->and($detection->ciDeploys()[0])->toMatchArray(['workflow' => 'deploy.yml', 'script' => 'deploy.sh', 'automatic' => true]);
});

it('suggests deploy settings from what it found', function () {
    $detection = ($this->detect)(tempProject([
        'compose.prod.yaml' => fixtureText('compose/blue-green.yaml'),
        '.github/workflows/deploy.yml' => fixtureText('workflows/ghcr-sha.yml'),
        'deploy.sh' => "#!/bin/sh\n",
        'src/api/artisan' => "<?php\n",
        'src/api/composer.json' => json_encode(['require' => ['laravel/framework' => '^13.0']]),
    ]));

    expect($detection->suggestedStackName('fallback'))->toBe('example')
        ->and($detection->suggestedAppServices())->toBe(['app-blue', 'app-green', 'worker'])
        // The colors have fixed container names, so `compose run` migrates in the worker.
        ->and($detection->suggestedMigrateService())->toBe('worker')
        ->and($detection->suggestedActivationScript())->toBe('deploy.sh')
        // A plain folder: the image CI builds, else the folder as it is.
        ->and($detection->suggestedStrategies(localFolder: true))->toBe([
            ['key' => 'ghcr_image', 'enabled' => true],
            ['key' => 'push_rsync', 'enabled' => true],
            ['key' => 'push_archive', 'enabled' => false],
            ['key' => 'server_git', 'enabled' => false],
        ]);
});

it('prefers a dedicated migration service', function () {
    $detection = ($this->detect)(tempProject([
        'docker-compose.prod.yml' => "services:\n  shop-api:\n    build: .\n  shop-migrate:\n    build: .\n",
        'artisan' => "<?php\n",
        'composer.json' => json_encode(['require' => ['laravel/framework' => '^12.0']]),
    ]));

    expect($detection->suggestedMigrateService())->toBe('shop-migrate')
        ->and($detection->framework())->toMatchArray(['name' => 'Laravel', 'version' => '12', 'path' => '']);
});

it('falls back to the folder name when compose has no name', function () {
    $detection = ($this->detect)(tempProject(['docker-compose.yml' => "services:\n  app:\n    build: .\n"]));

    expect($detection->suggestedStackName('example.dev'))->toBe('example-dev')
        ->and($detection->suggestedMigrateService())->toBeNull();
});
