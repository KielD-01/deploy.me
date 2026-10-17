<?php

use App\Actions\Targets\ReadFolder;
use App\Deploy\Conversion\RepositoryComparison;
use App\Events\TargetFolderRead;
use App\Jobs\ReadTargetFolder;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshResult;
use App\Platform\Ssh\Testing\FakeSshClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->ssh = new FakeSshClient;
    $this->app->instance(SshClient::class, $this->ssh);
    Event::fake([TargetFolderRead::class]);
});

it('reads what an earlier deployment left in the folder', function () {
    $target = conversionTarget(['app_services' => ['api']]);
    $compose = "services:\n  api:\n    image: demo\n    env_file:\n      - ./src/api/.env\n    volumes:\n      - api_storage:/app/storage/app\n      - ./uploads:/app/public/uploads\n"
        ."  queue:\n    image: demo\n    env_file: [src/api/.env]\nvolumes:\n  api_storage:\n";

    $this->ssh->on('box', 'deploy-convert-scan', function (string $alias, RemoteScript $script) use ($compose) {
        if (scriptVar($script, 'ONLY_PATHS') === '1') {
            return FakeSshClient::ok("__PATH__\tf\t1900\tsrc/api/.env\n__PATH__\td\t4096\tuploads\n");
        }

        return FakeSshClient::ok(implode("\n", [
            "__ITEM__\td\t7123456\t1543\t1756894980\t1\tsrc",
            "__ITEM__\tf\t9300\t1\t1756894980\t1\tdocker-compose.prod.yml",
            "__ITEM__\tf\t674\t1\t1757239680\t1\t.env",
            "__ITEM__\td\t4096\t2\t1756894980\t0\tuploads",
            '✓ 4 item(s) in /opt/srv/demo',
            '::deployme::items=4',
            '::deployme::image_tag='.str_repeat('b', 40),
            "__COMPOSE__\tdocker-compose.prod.yml",
            $compose,
            '__COMPOSE_END__',
            '::deployme::containers=5',
            '::deployme::working_dir=/opt/srv/demo',
            "__BIND__\tdemo-api-1\tuploads\t/app/public/uploads",
            "__VOLUME__\tdemo_api_storage",
            '::deployme::compose_ok=1',
            '::deployme::gnu=1',
            '::deployme::rsync=1',
            '::deployme::ok=1',
        ])."\n");
    });

    $scan = app(ReadFolder::class)($target, compare: false);

    expect(collect($scan['items'])->pluck('name')->all())->toBe(['src', 'docker-compose.prod.yml', '.env', 'uploads'])
        ->and($scan['items'][3]['same_fs'])->toBeFalse()
        ->and($scan['env_files'])->toBe(['src/api/.env' => ['api', 'queue']])
        ->and($scan['bind_sources'])->toBe(['uploads'])
        ->and($scan['binds'])->toBe([['container' => 'demo-api-1', 'source' => 'uploads', 'destination' => '/app/public/uploads']])
        ->and($scan['volumes'])->toBe(['demo_api_storage'])
        ->and($scan['image_tag'])->toBe(str_repeat('b', 40))
        ->and($scan['containers'])->toBe(5)
        ->and($scan['compose_ok'])->toBeTrue()
        ->and(collect($scan['paths'])->pluck('path')->all())->toBe(['src/api/.env', 'uploads'])
        ->and($scan)->not->toHaveKey('comparison')
        ->and($target->refresh()->folder_scan['items'])->toHaveCount(4)
        ->and($target->folder_scanned_at)->not->toBeNull();

    // The scan never asks the server for more than names, sizes and mounts.
    $first = $this->ssh->calls[0]['script'];
    expect(scriptVar($first, 'TARGET'))->toBe('/opt/srv/demo')
        ->and(scriptVar($first, 'STACK'))->toBe('demo')
        ->and(scriptVar($first, 'FILES'))->toBe('docker-compose.prod.yml');

    Event::assertDispatched(TargetFolderRead::class);
});

it('finds the commit the folder’s files came from, comparing them one by one', function () {
    config(['deployme.project_roots' => [sys_get_temp_dir()], 'deployme.ssh.runtime_dir' => sys_get_temp_dir().'/deployme-read-'.bin2hex(random_bytes(4))]);
    $repo = tempGitRepo(['src/index.php' => '<?php echo 1;', 'README.md' => 'hi']);
    $head = trim(Process::path($repo)->run(['git', 'rev-parse', 'HEAD'])->throw()->output());
    $target = demoTarget(['source' => 'local', 'local_path' => $repo, 'github_owner' => null, 'github_repo' => null]);
    $now = time();
    $this->ssh->on('box', 'deploy-convert-scan', FakeSshClient::ok(
        "__ITEM__\td\t20\t1\t{$now}\t1\tsrc\n__ITEM__\tf\t2\t1\t{$now}\t1\tREADME.md\n__ITEM__\tf\t9\t1\t{$now}\t1\t.env\n::deployme::rsync=1\n::deployme::ok=1\n"
    ));
    $commands = [];
    Process::fake(['*rsync*' => function ($process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result(">f+++++++++ 9 .env\n");
    }]);

    $scan = app(ReadFolder::class)($target);

    expect($scan['comparison'])->toMatchArray(['commit' => $head, 'message' => 'First commit', 'exact' => true, 'differs' => 0, 'tried' => 1])
        ->and($scan['comparison']['items']['src']['in_repo'])->toBeTrue()
        ->and($scan['comparison']['items']['README.md']['in_repo'])->toBeTrue()
        ->and($scan['comparison']['items']['.env'])->toBe(['server_only' => 1, 'differs' => 0, 'repo_only' => 0]);

    // A dry run from the server into an export on this side: nothing on the server can change.
    $rsync = $commands[0];
    expect($rsync)->toContain('--dry-run', '--checksum', '--delete', '--exclude=/.deploy/', '--exclude=/releases/', '--exclude=/shared/')
        ->and($rsync[count($rsync) - 2])->toBe('box:/opt/srv/demo/')
        ->and(end($rsync))->toStartWith(sys_get_temp_dir().'/deployme-compare-')
        ->and(is_dir(rtrim(end($rsync), '/')))->toBeFalse();
});

it('lists scheduled jobs that use the folder, without their secrets', function () {
    $target = conversionTarget();
    $this->ssh->on('box', 'deploy-convert-scan', FakeSshClient::ok(
        "__ITEM__\tf\t10\t1\t1\t1\tREADME.md\n"
        ."__CRON__\t* * * * * cd /opt/srv/demo && curl -s -H token=abc123 https://x.test/ping?key=".str_repeat('Z', 40)."\n"
        ."__CRON__\t/etc/cron.d/demo-backup\n::deployme::ok=1\n"
    ));

    $scan = app(ReadFolder::class)($target, compare: false);

    expect($scan['cron'])->toBe([
        '* * * * * cd /opt/srv/demo && curl -s -H token=… https://x.test/ping?key=…',
        '/etc/cron.d/demo-backup',
    ]);
});

it('says so when the folder isn’t there', function () {
    $target = conversionTarget();
    $this->ssh->on('box', 'deploy-convert-scan', new SshResult(3, "✗ /opt/srv/demo isn't on the server\n", '', 0.1));

    $scan = app(ReadFolder::class)($target);

    expect($scan['error'])->toBe("/opt/srv/demo isn't on the server")
        ->and($target->refresh()->folder_scan['reading'])->toBeNull();
});

it('only reads a folder someone asked about', function () {
    $target = conversionTarget();

    // A job that didn't come from start(): nothing marked the target, so nothing runs.
    (new ReadTargetFolder($target->id))->handle(app(ReadFolder::class));
    $this->ssh->assertNothingRan();
});

it('counts what rsync lists, per item of the folder', function () {
    expect(RepositoryComparison::tally(implode("\n", [
        '>f+++++++++ 674 .env',
        'cd+++++++++ 0 uploads/',
        '>f+++++++++ 10 uploads/a.jpg',
        '>fc........ 120 src/index.php',
        '>f+++++++++ 1900 src/api/.env',
        '*deleting   src/old.php',
        'cL+++++++++ 0 link -> elsewhere',
    ])))->toBe([
        '.env' => ['server_only' => 1, 'differs' => 0, 'repo_only' => 0],
        'uploads' => ['server_only' => 1, 'differs' => 0, 'repo_only' => 0],
        'src' => ['server_only' => 1, 'differs' => 1, 'repo_only' => 1],
        'link' => ['server_only' => 1, 'differs' => 0, 'repo_only' => 0],
    ]);
});
