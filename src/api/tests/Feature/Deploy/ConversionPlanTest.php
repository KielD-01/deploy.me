<?php

use App\Deploy\Conversion\ConversionPlanner;

it('plans what goes into the first release, what into shared/, and what stays', function () {
    $target = conversionTarget();
    $plan = app(ConversionPlanner::class)->plan($target, conversionScan());

    expect(collect($plan['rows'])->map(fn (array $row) => [$row['path'], $row['to'], $row['why']])->all())->toBe([
        ['src', 'release', 'In the repository · 1,543 files · 1 only on the server'],
        ['src/api/.env', 'shared', 'Env file of api and queue'],
        ['design', 'release', 'In the repository · 172 files'],
        ['docker-compose.prod.yml', 'release', 'In the repository · the stack demo runs from it'],
        ['.env', 'shared', 'Compose reads the stack’s variables from it'],
        ['.env.save', 'stays', 'Not in the repository · looks like an old copy of .env. Your call.'],
    ])
        ->and($plan['commit'])->toMatchArray(['sha' => str_repeat('c', 40), 'how' => 'compared'])
        ->and($plan['shared_paths'])->toBe(['src/api/.env', '.env'])
        ->and($plan['to_release'])->toBe(3)
        ->and($plan['to_shared'])->toBe(2)
        ->and($plan['stack']['volumes'])->toBe(['api_storage', 'meilisearch_data'])
        ->and(ConversionPlanner::ready($plan))->toBeTrue()
        ->and(collect($plan['checks'])->pluck('text')->all())->toBe([
            'One filesystem: every move is a rename, nothing is copied',
            'Compose resolves with src/api/.env and .env linked from shared/',
            'No GitHub workflow deploys into this folder',
            'No other run holds the folder’s lock',
        ]);

    $conversion = ConversionPlanner::conversion($target->project, $plan, restart: false);

    expect($conversion->items)->toBe([
        ['path' => 'src', 'type' => 'd', 'to' => 'release'],
        ['path' => 'src/api/.env', 'type' => 'f', 'to' => 'shared'],
        ['path' => 'design', 'type' => 'd', 'to' => 'release'],
        ['path' => 'docker-compose.prod.yml', 'type' => 'f', 'to' => 'release'],
        ['path' => '.env', 'type' => 'f', 'to' => 'shared'],
    ])
        ->and($conversion->stays)->toBe(['.env.save'])
        ->and($conversion->commit)->toBe(str_repeat('c', 40))
        ->and($conversion->sharedPaths)->toBe(['src/api/.env', '.env'])
        ->and($conversion->sharedPathsBefore)->toBeNull();
});

it('goes by what you pick', function () {
    $plan = app(ConversionPlanner::class)->plan(conversionTarget(), conversionScan(), [
        'design' => 'stays', 'src/api/.env' => 'release', '.env.save' => 'shared', 'src' => 'nonsense',
    ]);

    expect(collect($plan['rows'])->mapWithKeys(fn (array $row) => [$row['path'] => $row['to']])->all())->toBe([
        'src' => 'release', 'src/api/.env' => 'release', 'design' => 'stays', 'docker-compose.prod.yml' => 'release', '.env' => 'shared', '.env.save' => 'shared',
    ])
        ->and($plan['shared_paths'])->toBe(['.env', '.env.save']);
});

it('won’t convert while a GitHub workflow deploys into the folder', function () {
    $target = conversionTarget(['detection' => ['workflows' => [[
        'file' => '.github/workflows/deploy.yml', 'name' => 'Deploy', 'triggers' => ['push' => ['main']],
        'deploys' => ['via' => 'ssh', 'script' => 'deploy.sh', 'path' => '/opt/srv/demo'],
    ]]]]);

    $plan = app(ConversionPlanner::class)->plan($target, conversionScan());

    expect(ConversionPlanner::ready($plan))->toBeFalse()
        ->and(collect($plan['checks'])->firstWhere('state', 'fail'))->toMatchArray([
            'text' => 'A GitHub workflow deploys into this folder', 'workflow' => '.github/workflows/deploy.yml', 'script' => 'deploy.sh',
        ]);
});

it('keeps an item on another filesystem where it is', function () {
    $scan = conversionScan();
    $scan['items'][1]['same_fs'] = false;
    $target = conversionTarget();

    $plan = app(ConversionPlanner::class)->plan($target, $scan);
    expect(ConversionPlanner::ready($plan))->toBeFalse()
        ->and($plan['checks'][0]['text'])->toBe('design is on another filesystem, so it can’t move with a rename. Mark it Stays.');

    expect(ConversionPlanner::ready(app(ConversionPlanner::class)->plan($target, $scan, ['design' => 'stays'])))->toBeTrue();
});

it('leaves the call to you when it couldn’t compare the folder with the repository', function () {
    $plan = app(ConversionPlanner::class)->plan(conversionTarget(), conversionScan([
        'comparison' => ['error' => 'The repository isn’t on this Mac any more, so the files weren’t compared with it.'],
    ]));
    $rows = collect($plan['rows'])->keyBy('path');

    expect($rows['src']['to'])->toBe('stays')
        ->and($rows['src']['call'])->toBeTrue()
        ->and($rows->has('src/api/.env'))->toBeFalse()
        ->and($rows['docker-compose.prod.yml']['to'])->toBe('release')
        ->and($rows['.env']['to'])->toBe('shared')
        ->and($plan['comparison_error'])->toContain('isn’t on this Mac')
        ->and($plan['commit'])->toBeNull();
});

it('warns about a scheduled job that runs from the folder', function () {
    $plan = app(ConversionPlanner::class)->plan(conversionTarget(), conversionScan([
        'cron' => ['* * * * * cd /opt/srv/demo && ./core/prod/scripts/reconcile.sh >> /var/log/reconcile.log 2>&1'],
    ]));
    $check = collect($plan['checks'])->firstWhere('state', 'warn');

    expect($check['text'])->toBe('A scheduled job runs from this folder: after converting, point it at /opt/srv/demo/current')
        ->and($check['lines'])->toHaveCount(1)
        ->and(ConversionPlanner::ready($plan))->toBeTrue();
});

it('shares a bind mount from inside the folder, and blocks one of the whole folder', function () {
    $scan = conversionScan([
        'items' => [...conversionScan()['items'], ['name' => 'uploads', 'type' => 'd', 'bytes' => 4096, 'files' => 3, 'mtime' => 1, 'same_fs' => true]],
        'binds' => [['container' => 'demo-api-1', 'source' => 'uploads', 'destination' => '/app/public/uploads']],
    ]);

    $target = conversionTarget();

    $plan = app(ConversionPlanner::class)->plan($target, $scan);
    expect(collect($plan['rows'])->firstWhere('path', 'uploads'))->toMatchArray(['to' => 'shared', 'why' => 'Mounted into demo-api-1 at /app/public/uploads']);

    $scan['binds'][] = ['container' => 'demo-web-1', 'source' => '.', 'destination' => '/app'];
    expect(ConversionPlanner::ready(app(ConversionPlanner::class)->plan($target, $scan)))->toBeFalse();
});
