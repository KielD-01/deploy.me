<?php

use App\Actions\Targets\ReadFolder;
use App\Deploy\Conversion\ConversionPlanner;
use App\Deploy\Pipeline\PipelineRunner;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Enums\ReleaseStatus;
use App\Events\DeploymentLogged;
use App\Events\DeploymentUpdated;
use App\Events\TargetFolderRead;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectEnvFile;
use App\Models\Server;
use App\Models\Target;
use App\Platform\Ssh\KnownHosts;
use App\Platform\Ssh\ProcessSshClient;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshConfigCompiler;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;

// A real conversion on the local sandbox (`make sandbox`): a folder set up the
// way an earlier deployment leaves one, read, converted and put back, under
// /opt/srv, removed afterwards. Skipped when the sandbox isn't running.
beforeEach(function () {
    if (gethostbyname('deployme-sshd-target') === 'deployme-sshd-target' || ! is_readable(storage_path('app/sandbox-ssh/ssh_config'))) {
        $this->markTestSkipped('The sandbox isn’t running: `make sandbox`.');
    }

    config([
        'deployme.ssh.runtime_dir' => sys_get_temp_dir().'/deployme-it-'.bin2hex(random_bytes(4)),
        'deployme.ssh.user_known_hosts' => '/nonexistent',
        'deployme.project_roots' => [sys_get_temp_dir()],
        'deployme.sync.poll_ms' => 50,
    ]);

    $this->app->singleton(SshConfigCompiler::class, fn () => SshConfigCompiler::fromConfig());
    $this->app->singleton(KnownHosts::class);
    $this->app->singleton(SshClient::class, ProcessSshClient::class);
    app(KnownHosts::class)->trust(app(KnownHosts::class)->scan('sandbox'), null);

    Event::fake([DeploymentUpdated::class, DeploymentLogged::class, TargetFolderRead::class]);
    $this->slug = 'it-convert-'.bin2hex(random_bytes(3));
});

afterEach(function () {
    if (isset($this->slug)) {
        app(SshClient::class)->run('sandbox', RemoteScript::inline('cleanup', 'rm -rf -- "$DIR"', ['DIR' => "/opt/srv/{$this->slug}"]));
    }
});

function convertSandboxShell(string $script, array $vars = []): string
{
    return app(SshClient::class)->run('sandbox', RemoteScript::inline('check', $script, $vars))->throw()->stdout;
}

function sandboxConvertRun(Target $target, DeploymentKind $kind, array $options): Deployment
{
    $deployment = Deployment::factory()->create(['target_id' => $target->id, 'kind' => $kind, 'options' => $options]);
    app(PipelineRunner::class)->run($deployment);

    return $deployment->refresh();
}

it('reads, converts and puts back a folder deployed in place on a real server', function () {
    $files = [
        'src/api/index.php' => '<?php echo "v1";',
        'src/api/storage/.gitignore' => "*\n!.gitignore\n",
        'README.md' => 'Deployed by hand',
    ];
    $repo = tempGitRepo($files);
    $head = trim(Process::path($repo)->run(['git', 'rev-parse', 'HEAD'])->throw()->output());
    $root = "/opt/srv/{$this->slug}";

    // The earlier deployment: the repository's files, an env file, logs
    // the app wrote, and a stray copy of the env file.
    convertSandboxShell(<<<'SH'
        mkdir -p "$DIR/src/api/storage/logs"
        cd -- "$DIR"
        printf '%s' '<?php echo "v1";' > src/api/index.php
        printf '*\n!.gitignore\n' > src/api/storage/.gitignore
        printf '%s' 'Deployed by hand' > README.md
        printf 'APP_KEY=base64:sandbox\n' > .env
        printf 'APP_KEY=old\n' > .env.save
        printf 'boot\n' > src/api/storage/logs/app.log
        SH, ['DIR' => $root]);

    $server = Server::factory()->create(['ssh_alias' => 'sandbox', 'default_deploy_path' => '/opt/srv']);
    $project = Project::factory()->create([
        'slug' => $this->slug, 'name' => $this->slug,
        'source' => 'local', 'local_path' => $repo, 'github_owner' => null, 'github_repo' => null,
        'shared_paths' => ['src/api/storage'],
    ]);
    $project->envFiles()->create(['path' => '.env', 'role' => 'app', ...ProjectEnvFile::contentAttributes("APP_KEY=base64:sandbox\n")]);
    $target = Target::factory()->create(['server_id' => $server->id, 'project_id' => $project->id, 'base_dir' => '/opt/srv']);

    // Read it: every file of the repository matches the commit, by checksum.
    $scan = app(ReadFolder::class)($target);
    expect(collect($scan['items'])->pluck('name')->sort()->values()->all())->toBe(['.env', '.env.save', 'README.md', 'src'])
        ->and($scan['comparison'] ?? [])->toMatchArray(['commit' => $head, 'exact' => true]);

    $plan = app(ConversionPlanner::class)->plan($target->refresh(), $target->folder_scan);
    expect(collect($plan['rows'])->mapWithKeys(fn (array $row) => [$row['path'] => $row['to']])->all())->toBe([
        '.env' => 'shared', '.env.save' => 'stays', 'README.md' => 'release', 'src' => 'release', 'src/api/storage' => 'shared',
    ])->and(ConversionPlanner::ready($plan))->toBeTrue();

    // Convert it.
    $run = sandboxConvertRun($target, DeploymentKind::Convert, ['conversion' => ConversionPlanner::conversion($project, $plan, false)->toArray()]);
    $release = $run->release;
    expect($run->status)->toBe(DeploymentStatus::Succeeded, (string) $run->outcome);

    $layout = convertSandboxShell(<<<'SH'
        cd -- "$DIR"
        echo "current: $(readlink current)"
        echo "code: $(cat current/src/api/index.php)"
        echo "env: $(readlink "releases/$R/.env") $(cat current/.env)"
        echo "storage: $(readlink "releases/$R/src/api/storage") $(cat current/src/api/storage/logs/app.log)"
        echo "top: $(ls -A | sort | tr '\n' ' ')"
        echo "backup: $(ls -A ".deploy/convert-$R/old" | sort | tr '\n' ' ')"
        echo "linked: $(stat -c %i "releases/$R/README.md" ".deploy/convert-$R/old/README.md" | uniq | wc -l)"
        SH, ['DIR' => $root, 'R' => $release->name]);

    expect($layout)->toContain("current: releases/{$release->name}")
        ->toContain('code: <?php echo "v1";')
        ->toContain("env: {$root}/shared/.env APP_KEY=base64:sandbox")
        ->toContain("storage: {$root}/shared/src/api/storage boot")
        ->toContain('top: .deploy .env.save current releases shared ')
        ->toContain('backup: .env README.md src ')
        ->toContain('linked: 1')
        ->and($release->commit_sha)->toBe($head)
        ->and($project->refresh()->shared_paths)->toBe(['src/api/storage']);

    // Put it back.
    $undo = sandboxConvertRun($target->refresh(), DeploymentKind::UndoConvert, ['release_id' => $release->id]);
    expect($undo->status)->toBe(DeploymentStatus::Succeeded, (string) $undo->outcome);

    $restored = convertSandboxShell(<<<'SH'
        cd -- "$DIR"
        echo "top: $(ls -A | sort | tr '\n' ' ')"
        echo "releases: $(ls -A releases | wc -l) shared: $(find shared -mindepth 1 | wc -l)"
        echo "env: $(cat .env)"
        echo "storage: $(cat src/api/storage/logs/app.log)"
        if [ -L src/api/storage ]; then echo "storage is a link"; fi
        SH, ['DIR' => $root]);

    expect($restored)->toContain('top: .deploy .env .env.save README.md releases shared src ')
        ->toContain('releases: 0 shared: 0')
        ->toContain('env: APP_KEY=base64:sandbox')
        ->toContain('storage: boot')
        ->not->toContain('storage is a link')
        ->and($release->refresh()->status)->toBe(ReleaseStatus::Pruned)
        ->and($target->refresh()->current_release_id)->toBeNull()
        ->and($project->refresh()->shared_paths)->toBe(['src/api/storage']);
})->group('ssh-integration');
