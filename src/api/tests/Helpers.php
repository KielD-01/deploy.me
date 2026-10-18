<?php

use App\Deploy\Actions\ActionParser;
use App\Deploy\Pipeline\ResumePlanner;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectAction;
use App\Models\Release;
use App\Models\Server;
use App\Models\Target;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshResult;
use App\Platform\Ssh\Testing\FakeSshClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * A GitHub Actions run as the API lists it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function workflowRun(array $overrides = []): array
{
    return $overrides + [
        'id' => 1,
        'name' => 'CI',
        'workflow_id' => 1,
        'run_number' => 7,
        'status' => 'completed',
        'conclusion' => 'success',
        'event' => 'push',
        'head_sha' => 'new',
        'html_url' => 'https://github.com/o/r/actions/runs/1',
        'path' => '.github/workflows/ci.yml',
        'updated_at' => '2026-09-10T10:00:00Z',
    ];
}

/**
 * GitHub's API for one repository, KielD-01/demo: listed for the token, a
 * blue/green compose file, a workflow that pushes to GHCR, Laravel in
 * src/api, 312 commits and a green run. Anything else is refused.
 */
function fakeGitHubRepository(): void
{
    Http::preventStrayRequests();

    Http::fake([
        'api.github.com/user/repos*' => Http::response([[
            'full_name' => 'KielD-01/demo', 'name' => 'demo', 'owner' => ['login' => 'KielD-01'], 'private' => true,
            'archived' => false, 'language' => 'PHP', 'pushed_at' => now()->subHours(2)->toIso8601String(),
        ]]),
        'api.github.com/repos/KielD-01/demo/commits*' => Http::response(
            [['sha' => 'abc1234def', 'commit' => ['message' => "Add deploy\n\nDetails", 'committer' => ['date' => '2026-09-01T10:00:00Z']]]],
            200,
            ['Link' => '<https://api.github.com/repositories/1/commits?sha=main&per_page=1&page=2>; rel="next", <https://api.github.com/repositories/1/commits?sha=main&per_page=1&page=312>; rel="last"'],
        ),
        'api.github.com/repos/KielD-01/demo/git/trees/*' => Http::response(['truncated' => false, 'tree' => [
            ['path' => 'compose.prod.yaml', 'type' => 'blob'],
            ['path' => '.github/workflows/deploy.yml', 'type' => 'blob'],
            ['path' => 'src', 'type' => 'tree'],
            ['path' => 'src/api/artisan', 'type' => 'blob'],
            ['path' => 'src/api/composer.json', 'type' => 'blob'],
            ['path' => 'node_modules/left-pad/package.json', 'type' => 'blob'],
        ]]),
        'api.github.com/repos/KielD-01/demo/contents/compose.prod.yaml*' => Http::response(fixtureText('compose/blue-green.yaml')),
        'api.github.com/repos/KielD-01/demo/contents/.github/workflows/deploy.yml*' => Http::response(fixtureText('workflows/ghcr-sha.yml')),
        'api.github.com/repos/KielD-01/demo/contents/src/api/composer.json*' => Http::response(json_encode(['require' => ['laravel/framework' => '^13.0']])),
        'api.github.com/repos/KielD-01/demo/actions/runs*' => Http::response(['workflow_runs' => [workflowRun([
            'id' => 9, 'name' => 'Deploy', 'run_number' => 42, 'head_sha' => 'abc1234def',
            'html_url' => 'https://github.com/KielD-01/demo/actions/runs/9', 'path' => '.github/workflows/deploy.yml',
        ])]]),
        'api.github.com/repos/KielD-01/demo' => Http::response([
            'id' => 1, 'name' => 'demo', 'owner' => ['login' => 'KielD-01'], 'private' => true, 'default_branch' => 'main', 'language' => 'PHP',
        ]),
    ]);
}

// Pest's own fixture() returns a path; this returns the contents.
function fixtureText(string $path): string
{
    return (string) file_get_contents(__DIR__."/Fixtures/{$path}");
}

/**
 * A folder with the given files, for detection tests.
 *
 * @param  array<string, string>  $files  path => contents
 */
function tempProject(array $files): string
{
    $dir = sys_get_temp_dir().'/deployme-project-'.bin2hex(random_bytes(4));

    foreach ($files as $path => $contents) {
        if (! is_dir(dirname("{$dir}/{$path}"))) {
            mkdir(dirname("{$dir}/{$path}"), 0755, true);
        }
        file_put_contents("{$dir}/{$path}", $contents);
    }

    return $dir;
}

/**
 * A throwaway git repository with the files committed (feature tests only:
 * it runs the real git binary).
 *
 * @param  array<string, string>  $files  path => contents
 */
function tempGitRepo(array $files, ?string $remote = null): string
{
    $dir = tempProject($files);
    $git = fn (string ...$args) => Process::path($dir)
        ->env([
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_AUTHOR_NAME' => 'Test',
            'GIT_AUTHOR_EMAIL' => 'test@example.com',
            'GIT_COMMITTER_NAME' => 'Test',
            'GIT_COMMITTER_EMAIL' => 'test@example.com',
        ])
        ->run(['git', ...$args])
        ->throw();

    $git('init', '--quiet', '--initial-branch=main');
    if ($remote !== null) {
        $git('remote', 'add', 'origin', $remote);
    }
    $git('add', '--all');
    $git('commit', '--quiet', '-m', 'First commit');

    return $dir;
}

/**
 * Commits a change to a throwaway repository, so the next deploy has a commit
 * of its own (feature tests only: it runs the real git binary).
 */
function commitFile(string $repo, string $path, string $contents): void
{
    file_put_contents("{$repo}/{$path}", $contents);
    $git = fn (string ...$args) => Process::path($repo)->env([
        'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1',
        'GIT_AUTHOR_NAME' => 'Test', 'GIT_AUTHOR_EMAIL' => 'test@example.com',
        'GIT_COMMITTER_NAME' => 'Test', 'GIT_COMMITTER_EMAIL' => 'test@example.com',
    ])->run(['git', ...$args])->throw();

    $git('add', '--all');
    $git('commit', '--quiet', '-m', "Change {$path}");
}

/**
 * A project assigned to a server with the alias "box": /opt/srv/demo.
 *
 * @param  array<string, mixed>  $project
 */
function demoTarget(array $project = []): Target
{
    $server = Server::factory()->create(['ssh_alias' => 'box', 'default_deploy_path' => '/opt/srv']);
    $project = Project::factory()->create(['slug' => 'demo', 'name' => 'demo', ...$project]);

    return Target::factory()->create(['server_id' => $server->id, 'project_id' => $project->id, 'base_dir' => '/opt/srv']);
}

/**
 * What the deploy scripts print on a server where everything works.
 *
 * @param  array<string, SshResult|Throwable|Closure>  $overrides  by script name
 */
function fakeDeployScripts(FakeSshClient $ssh, array $overrides = [], string $alias = 'box'): FakeSshClient
{
    $defaults = [
        'deploy-preflight' => FakeSshClient::ok("✓ /opt/srv is writable for deploy\n::deployme::user=deploy\n::deployme::free=104857600000\n✓ 97 GB free\n::deployme::gnu_mv=1\n::deployme::ok=1\n"),
        'deploy-lease' => FakeSshClient::ok("✓ Locked /opt/srv/demo\n::deployme::lease=acquired\n"),
        'deploy-bootstrap' => FakeSshClient::ok("✓ /opt/srv/demo/releases and /opt/srv/demo/shared are ready\n::deployme::layout=releases\n"),
        'deploy-write-file' => FakeSshClient::ok("✓ written\n"),
        'deploy-build-ignore' => FakeSshClient::ok("✓ Dockerfile.dockerignore keeps 1 shared path(s) out of the build\n"),
        'deploy-state' => FakeSshClient::ok("✓ Wrote .deploy/state.json\n"),
        'deploy-unlock' => FakeSshClient::ok("✓ Unlocked /opt/srv/demo\n"),
        'deploy-link-shared' => FakeSshClient::ok("✓ storage → shared/storage\n"),
        'deploy-hook' => FakeSshClient::ok("hook ran\n"),
        'deploy-activate' => FakeSshClient::ok("✓ current → releases/20260911-120000\n::deployme::was=\n"),
        'deploy-run-script' => FakeSshClient::ok("\$ bash deploy.sh\nswitched\n"),
        'deploy-health' => FakeSshClient::ok("✓ https://demo.test/up answered 200 after 2 s\n::deployme::code=200\n::deployme::after=2\n"),
        'deploy-prune' => FakeSshClient::ok("✓ Removed releases/20260901-120000\n"),
        'deploy-prune-images' => FakeSshClient::ok("✓ Removed demo-app:aaaaaaa\n::deployme::removed=1\n::deployme::kept=0\n::deployme::gone=0\n"),
        'deploy-compose' => fn (string $alias, RemoteScript $script) => scriptVar($script, 'ACTION') === 'config'
            ? FakeSshClient::ok("✓ Compose reads the stack from this release: 3 service(s)\n::deployme::services=3\n")
            : FakeSshClient::ok("\$ docker compose -p demo up -d\nContainer app  Started\n"),
        'deploy-compose-health' => FakeSshClient::ok("app running, healthy\n✓ Everything is up after 4 s\n::deployme::after=4\n"),
        'deploy-compose-roll' => FakeSshClient::ok("  api: 2 running\n✓ 2 containers started beside 2 still serving\n::deployme::started=2\n::deployme::retired=2\n"),
        'deploy-db-backup' => FakeSshClient::ok("✓ demo-20260911-120000.dump (2048 bytes)\n::deployme::file=demo-20260911-120000.dump\n::deployme::size=2048\n"),
        'deploy-inspect' => FakeSshClient::ok("✓ 7 item(s) in /opt/srv/demo\n::deployme::files=7\n"),
        'deploy-registry-login' => FakeSshClient::ok("✓ Logged in to ghcr.io\n"),
        'deploy-forget-login' => FakeSshClient::ok("✓ Forgot this deploy's registry login\n"),
        'deploy-server-git' => FakeSshClient::ok("✓ unpacked\n"),
        'deploy-kill' => FakeSshClient::ok("· Nothing of it was still running\n"),
        'deploy-sync-in-place' => FakeSshClient::ok("✓ Synced release 20260911-120000 into /opt/srv/demo: 3 file(s) updated, 1 removed\n::deployme::updated=3\n::deployme::deleted=1\n"),
    ];

    foreach ([...$defaults, ...$overrides] as $script => $response) {
        $ssh->on($alias, $script, $response);
    }

    return $ssh;
}

/**
 * A retry of a finished deploy from where it stopped, as the run page starts
 * it, but not queued: the test runs it.
 */
function retryDeployment(Deployment $run): Deployment
{
    $point = ResumePlanner::for($run->refresh()) ?? throw new RuntimeException("Run #{$run->id} can’t be retried from a step.");

    return Deployment::factory()->create([
        'target_id' => $run->target_id, 'kind' => $run->kind, 'options' => $run->options, 'trigger' => 'retry',
        'retry_of_id' => $run->id, 'resume_from' => $point->key, 'strategy' => $run->strategy,
    ]);
}

/**
 * A variable a RemoteScript sends, decoded.
 */
function scriptVar(RemoteScript $script, string $name): ?string
{
    return preg_match('/^'.preg_quote($name, '/')."=\\$\\(printf %s '([^']*)'/m", $script->render(), $match) === 1 ? base64_decode($match[1]) : null;
}

/**
 * What deploy-convert.sh prints in each mode on a server where converting works.
 *
 * @param  array<string, SshResult>  $modes  by mode
 */
function fakeConvertScripts(FakeSshClient $ssh, array $modes = [], string $alias = 'box'): FakeSshClient
{
    $responses = [
        'check' => FakeSshClient::ok("✓ The folder is as the plan saw it: 3 item(s) to move\n"),
        'backup' => FakeSshClient::ok("✓ 3 item(s) linked into .deploy/convert/old, nothing copied\n::deployme::backed_up=3\n"),
        'move' => FakeSshClient::ok("src → releases/x/src\n✓ 2 into the release, 2 into shared/\n::deployme::to_release=2\n::deployme::to_shared=2\n"),
        'link' => FakeSshClient::ok("✓ .env → shared/.env\n::deployme::linked=2\n"),
        'compose' => FakeSshClient::ok("✓ Compose reads the stack from the release: 6 service(s)\n::deployme::services=6\n"),
        'undo' => FakeSshClient::ok("✓ 4 item(s) are back where they were\n::deployme::back=4\n"),
        'drop-backup' => FakeSshClient::ok("✓ Removed the backup\n"),
        ...$modes,
    ];

    return $ssh->on($alias, 'deploy-convert', fn (string $alias, RemoteScript $script) => $responses[scriptVar($script, 'MODE')]
        ?? new RuntimeException('Unexpected deploy-convert mode '.scriptVar($script, 'MODE')));
}

/**
 * A Docker project on the fake server "box", deployed in place before
 * deploy.me: /opt/srv/demo, stack demo.
 *
 * @param  array<string, mixed>  $project
 */
function conversionTarget(array $project = []): Target
{
    return demoTarget(['dockerized' => true, 'stack_name' => 'demo', 'compose_files' => ['docker-compose.prod.yml'], 'app_services' => ['api', 'queue'], ...$project]);
}

/**
 * A read of that folder (targets.folder_scan): code and a compose file that
 * match commit cccc…, env files the stack reads, and a stray copy of .env.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function conversionScan(array $overrides = []): array
{
    $item = fn (string $name, string $type, int $bytes, int $files = 1) => ['name' => $name, 'type' => $type, 'bytes' => $bytes, 'files' => $files, 'mtime' => 1756894980, 'same_fs' => true];

    return [
        'items' => [
            $item('src', 'd', 7123456, 1543),
            $item('design', 'd', 16190000, 172),
            $item('docker-compose.prod.yml', 'f', 9300),
            $item('.env', 'f', 674),
            $item('.env.save', 'f', 630),
        ],
        'paths' => [['path' => 'src/api/.env', 'type' => 'f', 'bytes' => 1900]],
        'tracked' => null,
        'binds' => [],
        'volumes' => ['demo_api_storage', 'demo_meilisearch_data'],
        'env_files' => ['src/api/.env' => ['api', 'queue']],
        'bind_sources' => [],
        'git' => null,
        'image_tag' => null,
        'containers' => 5,
        'working_dir' => '/opt/srv/demo',
        'compose_ok' => true,
        'compose_error' => null,
        'locked_by' => null,
        'gnu' => true,
        'rsync' => true,
        'current_dir' => false,
        'comparison' => [
            'commit' => str_repeat('c', 40),
            'message' => 'fix(web): stop the air-raid bar repainting',
            'date' => null,
            'exact' => true,
            'differs' => 0,
            'items' => [
                'src' => ['server_only' => 1, 'differs' => 0, 'repo_only' => 0, 'in_repo' => true],
                'design' => ['server_only' => 0, 'differs' => 0, 'repo_only' => 0, 'in_repo' => true],
                'docker-compose.prod.yml' => ['server_only' => 0, 'differs' => 0, 'repo_only' => 0, 'in_repo' => true],
                '.env' => ['server_only' => 1, 'differs' => 0, 'repo_only' => 0],
                '.env.save' => ['server_only' => 1, 'differs' => 0, 'repo_only' => 0],
            ],
        ],
        ...$overrides,
    ];
}

function probeFixture(string $name = 'ubuntu-24.04'): string
{
    return (string) file_get_contents(__DIR__."/Fixtures/probe/{$name}.json");
}

/**
 * What resources/ssh-scripts/probe.sh prints, wrapped in its sentinels.
 */
function probeOutput(string $name = 'ubuntu-24.04'): SshResult
{
    return FakeSshClient::ok("__DEPLOYME_PROBE_BEGIN__\n".probeFixture($name)."\n__DEPLOYME_PROBE_END__\n");
}

/**
 * What resources/ssh-scripts/ls.sh prints for a directory.
 *
 * @param  list<array{0: string, 1: string, 2: string, 3: bool}>  $folders  [name, owner, mode, writable]
 */
function lsOutput(string $path, array $folders, bool $writable = false): SshResult
{
    $entries = '';
    $writableNames = '';

    foreach ($folders as [$name, $owner, $mode, $isWritable]) {
        $entries .= "d\td\t{$mode}\t{$owner}\t{$owner}\t4096\t1694351234.5\t{$name}\0";
        $writableNames .= $isWritable ? "{$name}\0" : '';
    }

    return FakeSshClient::ok(
        "__DEPLOYME_CWD__ {$path}\n__DEPLOYME_CWD_WRITABLE__ ".($writable ? 1 : 0)."\n"
        ."__DEPLOYME_DF__ /dev/sda1 ext4 171798691840 104793882624 /\n"
        ."__DEPLOYME_ENTRIES__\n{$entries}\n__DEPLOYME_WRITABLE__\n{$writableNames}"
    );
}

/**
 * The signed-in person on the Classic theme, for a test about a page's classic
 * view (the Console theme has its own dashboard, the session).
 */
function asClassic(): void
{
    auth()->user()?->forceFill(['ui' => ['theme' => 'classic']])->save();
}

/**
 * Starts tests/Fixtures/fake-proxy.php (SOCKS5 or HTTP CONNECT) on a free
 * local port. Stop it with stopFakeProxy().
 *
 * @return array{port: int, process: resource}
 */
function fakeProxy(string $kind = 'socks5', ?string $user = null, ?string $password = null): array
{
    $portFile = tempnam(sys_get_temp_dir(), 'fake-proxy');
    unlink($portFile);
    $command = [PHP_BINARY, __DIR__.'/Fixtures/fake-proxy.php', $kind, $user ?? '-', $password ?? '-', $portFile];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

    for ($i = 0; $i < 100 && ! is_file($portFile); $i++) {
        usleep(20_000);
    }

    $port = (int) file_get_contents($portFile);
    unlink($portFile);

    return ['port' => $port, 'process' => $process];
}

function stopFakeProxy(array $proxy): void
{
    // The proxy leads its own process group, so this takes its forks too.
    posix_kill(-proc_get_status($proxy['process'])['pid'], SIGKILL);
    proc_close($proxy['process']);
}

/**
 * A Docker project on "box" with a live release: /opt/srv/demo, stack demo,
 * release 20261017-161400 at commit 9fabf06..., where actions run.
 *
 * @param  array<string, mixed>  $project
 */
function actionTarget(array $project = []): Target
{
    $target = demoTarget(['dockerized' => true, 'stack_name' => 'demo', 'compose_files' => ['compose.yaml'], 'app_services' => ['api'], ...$project]);
    $release = Release::factory()->create([
        'target_id' => $target->id, 'name' => '20261017-161400', 'commit_sha' => '9fabf06'.str_repeat('0', 33),
        'layout' => $target->project->versionable ? Release::IN_RELEASES : Release::IN_PLACE,
        'path' => $target->project->versionable ? '/opt/srv/demo/releases/20261017-161400' : '/opt/srv/demo',
    ]);
    $target->update(['current_release_id' => $release->id]);

    return $target->refresh();
}

/**
 * An action of the project, from the YAML of a .deployme/actions file.
 */
function projectAction(Project $project, string $yaml, string $key = 'reindex', string $source = ProjectAction::APP): ProjectAction
{
    [$definition, $errors] = ActionParser::yaml($yaml);

    if ($definition === null) {
        throw new RuntimeException('The test action doesn’t parse: '.implode(' ', $errors));
    }

    return ProjectAction::query()->create([
        'project_id' => $project->id, 'source' => $source, 'key' => $key, 'name' => $definition->name(),
        'file' => $source === ProjectAction::REPO ? ".deployme/actions/{$key}.yml" : null,
        'definition' => $definition->data, 'scheduled_through' => now(),
    ]);
}
