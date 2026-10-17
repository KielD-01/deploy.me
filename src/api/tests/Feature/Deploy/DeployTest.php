<?php

use App\Actions\Deployments\CancelDeployment;
use App\Deploy\Pipeline\DeploymentCancelled;
use App\Deploy\Pipeline\PipelineRunner;
use App\Deploy\Pipeline\ResumePlanner;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Enums\ReleaseStatus;
use App\Enums\TargetHealth;
use App\Events\DeploymentLogged;
use App\Events\DeploymentUpdated;
use App\Models\Deployment;
use App\Models\ProjectEnvFile;
use App\Models\Release;
use App\Models\Target;
use App\Platform\Ssh\Exceptions\SshTimeout;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshResult;
use App\Platform\Ssh\Testing\FakeSshClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config([
        'deployme.project_roots' => [sys_get_temp_dir()],
        'deployme.sync.poll_ms' => 0,
        'deployme.ssh.runtime_dir' => sys_get_temp_dir().'/deployme-deploy-'.bin2hex(random_bytes(4)),
    ]);

    $this->ssh = fakeDeployScripts(new FakeSshClient);
    $this->app->instance(SshClient::class, $this->ssh);
    Event::fake([DeploymentUpdated::class, DeploymentLogged::class]);

    // rsync is faked, and each command kept for the assertions (or failed,
    // while rsyncFails says so); git runs for real against a throwaway repository.
    $this->rsync = [];
    $this->rsyncFails = false;
    Process::fake(['*rsync*' => function ($process) {
        $this->rsync[] = $process->command;

        return $this->rsyncFails
            ? Process::result('', "rsync: [receiver] write failed on \"index.php\": No space left on device (28)\n", 11)
            : Process::result("Number of files: 12 (reg: 9, dir: 3)\nNumber of regular files transferred: 9\nTotal file size: 20480 bytes\nTotal transferred file size: 4096 bytes\n");
    }]);
});

/**
 * A project in a local git repository, assigned to the fake server "box" at /opt/srv/demo.
 */
function gitProjectTarget(array $project = []): Target
{
    $repo = tempGitRepo(['index.php' => '<?php echo "hello";', 'storage/.gitignore' => "*\n!.gitignore\n"]);

    return demoTarget([
        'source' => 'local',
        'local_path' => $repo,
        'github_owner' => null,
        'github_repo' => null,
        'shared_paths' => ['storage'],
        'delivery_strategies' => [['key' => 'push_archive', 'enabled' => true], ['key' => 'push_rsync', 'enabled' => true]],
        ...$project,
    ]);
}

function runDeploy(Target $target, DeploymentKind $kind = DeploymentKind::Deploy, array $options = []): Deployment
{
    $deployment = Deployment::factory()->create(['target_id' => $target->id, 'kind' => $kind, 'options' => $options]);
    app(PipelineRunner::class)->run($deployment);

    return $deployment->refresh();
}

function stepMap(Deployment $deployment): array
{
    return $deployment->steps()->get()->mapWithKeys(fn ($step) => [$step->key => $step->status->value])->all();
}

/**
 * The arguments of the last rsync a test's deploys ran.
 *
 * @return list<string>
 */
function lastRsyncOf(object $test): array
{
    return $test->rsync === [] ? [] : $test->rsync[array_key_last($test->rsync)];
}

function headOf(string $repo): string
{
    return trim(Process::path($repo)->run(['git', 'rev-parse', 'HEAD'])->throw()->output());
}

/**
 * A deploy where "Cancel" is clicked while the given script runs: the script
 * answers, and the run's next check (every tick, here) finds the cancel.
 *
 * @param  (Closure(): SshResult)|null  $answer
 */
function deployCancelledDuring(FakeSshClient $ssh, Target $target, string $script, ?Closure $answer = null): Deployment
{
    config(['deployme.deploy.cancel_check_ms' => 0]);
    $deployment = Deployment::factory()->create(['target_id' => $target->id, 'kind' => DeploymentKind::Deploy]);

    $ssh->on('box', $script, function () use ($deployment, $answer) {
        app(CancelDeployment::class)($deployment);

        return $answer !== null ? $answer() : FakeSshClient::ok("still going\n");
    });

    app(PipelineRunner::class)->run($deployment);

    return $deployment->refresh();
}

function hookFor(Target $target, string $phase): void
{
    $target->project->hooks()->create(['phase' => $phase, 'name' => "{$phase} hook", 'command' => 'php artisan about', 'run_in' => 'host', 'timeout' => 300, 'position' => 1]);
}

it('deploys a commit into a new release and switches current to it', function () {
    $target = gitProjectTarget();
    $target->project->envFiles()->create(['path' => '.env', 'role' => 'app', ...ProjectEnvFile::contentAttributes("APP_NAME=Demo\n")]);
    $sha = headOf($target->project->local_path);

    $deployment = runDeploy($target);
    $release = $deployment->release;

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded, (string) $deployment->outcome)
        ->and(stepMap($deployment))->toBe([
            'resolve' => 'succeeded', 'preflight' => 'succeeded', 'lease' => 'succeeded', 'folders' => 'succeeded',
            'upload' => 'succeeded', 'shared' => 'succeeded', 'env' => 'succeeded', 'pins' => 'skipped', 'stack' => 'skipped', 'hooks_after_upload' => 'skipped',
            'login' => 'skipped', 'pull' => 'skipped', 'build' => 'skipped', 'backup' => 'skipped', 'migrate' => 'skipped', 'hooks_release' => 'skipped',
            'activate' => 'succeeded', 'roll' => 'skipped', 'recreate' => 'skipped', 'health' => 'skipped',
            'hooks_after_activate' => 'skipped', 'state' => 'succeeded', 'prune' => 'succeeded', 'prune_images' => 'skipped',
            'rollback' => 'skipped', 'unlock' => 'succeeded',
        ])
        ->and($deployment->outcome)->toBe("Release {$release->name} (".substr($sha, 0, 7).") is live on {$target->server->name}.")
        ->and($deployment->strategy)->toBe('push_archive')
        ->and($deployment->commit_sha)->toBe($sha)
        ->and($deployment->commit_message)->toBe('First commit')
        ->and($deployment->strategy_trace[0])->toMatchArray(['key' => 'push_archive', 'state' => 'applicable'])
        ->and($deployment->strategy_trace[1])->toMatchArray(['key' => 'push_rsync', 'state' => 'not_needed'])
        ->and($release->status)->toBe(ReleaseStatus::Ready)
        ->and($release->name)->toMatch('/^\d{8}-\d{6}$/')
        ->and($release->path)->toBe("/opt/srv/demo/releases/{$release->name}")
        ->and($release->size_bytes)->toBe(20480)
        ->and($target->refresh()->current_release_id)->toBe($release->id)
        ->and($target->previous_release_id)->toBeNull()
        ->and($deployment->steps()->where('key', 'upload')->value('detail'))->toBe('9 files sent · 4 KB');

    $rsync = lastRsyncOf($this);
    $source = $rsync[count($rsync) - 2];
    expect($rsync)->toContain('--checksum', '--filter=- .git', '--filter=- .env', '--filter=- /.env', '--filter=+ .env.example')
        ->not->toContain('--filter=:- .gitignore')
        ->and(end($rsync))->toBe("box:/opt/srv/demo/releases/{$release->name}/")
        ->and(collect($rsync)->first(fn (string $arg) => str_starts_with($arg, '--link-dest')))->toBeNull()
        // The export of the commit is gone once the run is over.
        ->and(is_dir(rtrim($source, '/')))->toBeFalse();

    $activate = collect($this->ssh->calls)->first(fn (array $call) => $call['script']->name === 'deploy-activate');
    $shared = collect($this->ssh->calls)->first(fn (array $call) => $call['script']->name === 'deploy-link-shared');
    expect($activate['script']->render())->toContain(base64_encode($release->name))
        ->and($shared['script']->render())->toContain(base64_encode('storage'), base64_encode('.env'));
});

it('links unchanged files to the live release on the next deploy', function () {
    $target = gitProjectTarget();
    $first = runDeploy($target)->release;

    $second = runDeploy($target);

    expect($second->status)->toBe(DeploymentStatus::Succeeded)
        ->and($second->release->name)->not->toBe($first->name)
        ->and(lastRsyncOf($this))->toContain("--link-dest=/opt/srv/demo/releases/{$first->name}")
        ->and($second->steps()->where('key', 'upload')->value('detail'))->toEndWith('· the rest unchanged')
        ->and($target->refresh()->current_release_id)->toBe($second->release_id)
        ->and($target->previous_release_id)->toBe($first->id);
});

it('switches back to the previous release when the health check fails', function () {
    $target = gitProjectTarget(['health_url' => 'https://demo.test/up', 'health_timeout' => 30]);
    $first = runDeploy($target)->release;
    expect($target->refresh()->health)->toBe(TargetHealth::Healthy);

    fakeDeployScripts($this->ssh, ['deploy-health' => new SshResult(30, "✗ https://demo.test/up gave HTTP 500 for 30 s\n::deployme::last=HTTP 500\n", '', 30.0)]);
    $failed = runDeploy($target);

    expect($failed->status)->toBe(DeploymentStatus::RolledBack)
        ->and($failed->outcome)->toBe("Health check failed after 30 s: https://demo.test/up gave HTTP 500. Switched back to release {$first->name}, which is live again.")
        ->and(stepMap($failed))->toMatchArray(['health' => 'failed', 'hooks_after_activate' => 'skipped', 'prune' => 'skipped', 'rollback' => 'succeeded', 'unlock' => 'succeeded'])
        ->and($failed->steps()->where('key', 'rollback')->value('label'))->toBe("Switch back to {$first->name}")
        ->and($failed->release->status)->toBe(ReleaseStatus::Failed)
        ->and($target->refresh()->current_release_id)->toBe($first->id);

    $switches = collect($this->ssh->calls)->filter(fn (array $call) => $call['script']->name === 'deploy-activate')->values();
    expect($switches->last()['script']->render())->toContain(base64_encode($first->name));
});

it('leaves a failed release live when rolling back is off', function () {
    $target = gitProjectTarget(['health_url' => 'https://demo.test/up', 'auto_rollback' => false]);
    runDeploy($target);
    fakeDeployScripts($this->ssh, ['deploy-health' => new SshResult(30, "::deployme::last=no answer\n", '', 30.0)]);

    $failed = runDeploy($target);

    expect($failed->status)->toBe(DeploymentStatus::Failed)
        ->and(stepMap($failed))->toMatchArray(['rollback' => 'skipped'])
        ->and($target->refresh()->current_release_id)->toBe($failed->release_id)
        ->and($target->health)->toBe(TargetHealth::Unhealthy);
});

it('switches back when cancelled after the switch, whatever auto-rollback says', function () {
    $target = gitProjectTarget(['auto_rollback' => false]);
    $first = runDeploy($target)->release;
    hookFor($target, 'after_activate');

    $cancelled = deployCancelledDuring($this->ssh, $target, 'deploy-hook');

    expect($cancelled->status)->toBe(DeploymentStatus::Cancelled)
        ->and($cancelled->outcome)->toBe("Cancelled before it finished. Switched back to release {$first->name}, which is live again.")
        ->and(stepMap($cancelled))->toMatchArray([
            'activate' => 'succeeded', 'hooks_after_activate' => 'cancelled', 'state' => 'skipped', 'prune' => 'skipped',
            'rollback' => 'succeeded', 'unlock' => 'succeeded',
        ])
        ->and($cancelled->release->status)->toBe(ReleaseStatus::Failed)
        ->and($target->refresh()->current_release_id)->toBe($first->id);

    // The cancel is still pending while the cleanup runs, and checked on every
    // tick here: neither the switch back nor the unlock stops for it.
    $switches = collect($this->ssh->calls)->filter(fn (array $call) => $call['script']->name === 'deploy-activate')->values();
    expect($switches)->toHaveCount(3)
        ->and(scriptVar($switches->last()['script'], 'RELEASE'))->toBe($first->name)
        ->and(collect($this->ssh->calls)->pluck('script.name')->slice(-2)->values()->all())->toBe(['deploy-activate', 'deploy-unlock'])
        ->and($cancelled->logLines()->pluck('line')->implode("\n"))->not->toContain('! Switch back')->not->toContain('! Clean up');
});

it('leaves the live release alone when cancelled before the switch', function () {
    $target = gitProjectTarget();
    $first = runDeploy($target)->release;
    hookFor($target, 'release');

    $cancelled = deployCancelledDuring($this->ssh, $target, 'deploy-hook');

    expect($cancelled->status)->toBe(DeploymentStatus::Cancelled)
        ->and($cancelled->outcome)->toBe('Cancelled before it finished.')
        ->and(stepMap($cancelled))->toMatchArray(['hooks_release' => 'cancelled', 'activate' => 'skipped', 'rollback' => 'skipped', 'unlock' => 'succeeded'])
        ->and($cancelled->release->status)->toBe(ReleaseStatus::Failed)
        ->and($target->refresh()->current_release_id)->toBe($first->id)
        ->and(collect($this->ssh->calls)->filter(fn (array $call) => $call['script']->name === 'deploy-activate'))->toHaveCount(1);
});

it('finishes its cleanup when a cancel comes in during it, and gives the lock back', function () {
    $target = gitProjectTarget();
    hookFor($target, 'release');
    fakeDeployScripts($this->ssh, ['deploy-hook' => new SshResult(1, "SQLSTATE[08006] connection refused\n", '', 1.0)]);

    // The hook fails, and "Cancel" is clicked while the lock is being given back.
    $failed = deployCancelledDuring($this->ssh, $target, 'deploy-unlock', fn () => FakeSshClient::ok("✓ Unlocked /opt/srv/demo\n"));

    expect($failed->status)->toBe(DeploymentStatus::Failed)
        ->and($failed->cancel_requested_at)->not->toBeNull()
        ->and(stepMap($failed))->toMatchArray(['hooks_release' => 'failed', 'unlock' => 'succeeded']);
});

it('stops what a script started on the server once deploy.me lets go of it', function (Throwable $letGo, DeploymentStatus $status, string $step) {
    $target = gitProjectTarget();
    hookFor($target, 'release');
    // What ProcessSshClient throws once it has closed the ssh session.
    fakeDeployScripts($this->ssh, ['deploy-hook' => $letGo]);

    $run = runDeploy($target);
    $scripts = collect($this->ssh->calls)->pluck('script')->values();
    $hook = $scripts->search(fn (RemoteScript $script) => $script->name === 'deploy-hook');
    $pgid = "/opt/srv/demo/.deploy/runs/{$run->id}.pgid";

    expect($run->status)->toBe($status)
        ->and(stepMap($run))->toMatchArray(['hooks_release' => $step, 'unlock' => 'succeeded'])
        ->and(scriptVar($scripts[$hook], 'PGID_FILE'))->toBe($pgid)
        ->and($scripts[$hook]->command())->toContain('setsid --wait bash -s')
        // Right after: the script's process group goes.
        ->and($scripts[$hook + 1]->name)->toBe('deploy-kill')
        ->and(scriptVar($scripts[$hook + 1], 'FILE'))->toBe($pgid)
        ->and(scriptVar($scripts[$hook + 1], 'PGID_FILE'))->toBeNull()
        ->and($scripts[$hook + 1]->command())->toBe('bash -s');
})->with([
    'a cancel' => [new DeploymentCancelled, DeploymentStatus::Cancelled, 'cancelled'],
    'a timeout' => [new SshTimeout('No answer from box within 300 s.'), DeploymentStatus::Failed, 'failed'],
]);

it('retries from the upload that failed, reusing its release, after the checks and the lock', function () {
    $target = gitProjectTarget();
    $first = runDeploy($target)->release;
    $this->rsyncFails = true;
    $failed = runDeploy($target);
    $this->rsyncFails = false;
    $releases = Release::query()->count();

    fakeDeployScripts($this->ssh, ['deploy-activate' => function () use ($failed) {
        $this->statusWhileRetrying = $failed->release->fresh()->status;

        return FakeSshClient::ok("✓ current → releases/{$failed->release->name}\n");
    }]);
    $retry = retryDeployment($failed);
    app(PipelineRunner::class)->run($retry);
    $retry->refresh();
    $rsync = lastRsyncOf($this);

    expect(stepMap($failed))->toMatchArray(['upload' => 'failed'])
        ->and($retry->status)->toBe(DeploymentStatus::Succeeded, (string) $retry->outcome)
        ->and($retry->resume_from)->toBe('upload')
        ->and($retry->release_id)->toBe($failed->release_id)
        ->and(Release::query()->count())->toBe($releases)
        ->and(stepMap($retry))->toMatchArray([
            'restore' => 'succeeded', 'preflight' => 'succeeded', 'lease' => 'succeeded', 'folders' => 'skipped',
            'upload' => 'succeeded', 'shared' => 'succeeded', 'activate' => 'succeeded', 'state' => 'succeeded', 'unlock' => 'succeeded',
        ])
        ->and($retry->steps()->where('key', 'folders')->value('detail'))->toBe("done in #{$failed->id}")
        ->and($retry->steps()->where('key', 'restore')->value('label'))->toBe("Reuse release {$failed->release->name}")
        ->and($this->statusWhileRetrying)->toBe(ReleaseStatus::Building)
        ->and($failed->release->refresh()->status)->toBe(ReleaseStatus::Ready)
        ->and($target->refresh()->current_release_id)->toBe($failed->release_id)
        ->and($target->previous_release_id)->toBe($first->id)
        ->and($rsync)->toContain("--link-dest=/opt/srv/demo/releases/{$first->name}")
        ->and(end($rsync))->toBe("box:/opt/srv/demo/releases/{$failed->release->name}/");
});

it('retries from the health check that failed: switches to the release again, then checks it', function () {
    $target = gitProjectTarget(['health_url' => 'https://demo.test/up', 'health_timeout' => 30]);
    $first = runDeploy($target)->release;
    fakeDeployScripts($this->ssh, ['deploy-health' => new SshResult(30, "✗ https://demo.test/up gave HTTP 500 for 30 s\n::deployme::last=HTTP 500\n", '', 30.0)]);
    $failed = runDeploy($target);
    expect($failed->status)->toBe(DeploymentStatus::RolledBack);

    // The app answers now.
    fakeDeployScripts($this->ssh);
    $uploads = count($this->rsync);
    $before = count($this->ssh->calls);
    $retry = retryDeployment($failed);
    app(PipelineRunner::class)->run($retry);
    $retry->refresh();
    $ran = array_slice($this->ssh->calls, $before);

    expect($retry->status)->toBe(DeploymentStatus::Succeeded, (string) $retry->outcome)
        ->and($retry->resume_from)->toBe('health')
        ->and(stepMap($retry))->toMatchArray([
            'restore' => 'succeeded', 'preflight' => 'succeeded', 'lease' => 'succeeded', 'upload' => 'skipped', 'shared' => 'skipped',
            'activate' => 'succeeded', 'health' => 'succeeded', 'state' => 'succeeded', 'rollback' => 'skipped', 'unlock' => 'succeeded',
        ])
        ->and($retry->steps()->where('key', 'upload')->value('detail'))->toBe("done in #{$failed->id}")
        ->and($retry->steps()->where('key', 'rollback')->value('label'))->toBe("Switch back to {$first->name}")
        ->and(array_map(fn (array $call) => $call['script']->name, $ran))->toBe(['deploy-preflight', 'deploy-lease', 'deploy-activate', 'deploy-health', 'deploy-state', 'deploy-unlock'])
        ->and(scriptVar($ran[2]['script'], 'RELEASE'))->toBe($failed->release->name)
        ->and(count($this->rsync))->toBe($uploads)
        ->and($failed->release->refresh()->status)->toBe(ReleaseStatus::Ready)
        ->and($target->refresh()->current_release_id)->toBe($failed->release_id)
        ->and($target->previous_release_id)->toBe($first->id)
        ->and($target->health)->toBe(TargetHealth::Healthy);
});

it('won’t retry a deploy from its step once another release went live', function () {
    $target = gitProjectTarget();
    $first = runDeploy($target)->release;
    runDeploy($target);
    $this->rsyncFails = true;
    $failed = runDeploy($target);
    $this->rsyncFails = false;

    expect(ResumePlanner::for($failed)?->key)->toBe('upload');

    // A rollback to the first release: something else is live now.
    runDeploy($target, DeploymentKind::Rollback, ['release_id' => $first->id]);

    expect($failed->release->refresh()->pruned_at)->toBeNull()
        ->and(ResumePlanner::for($failed->refresh()))->toBeNull();
});

it('deploys in place without touching the server’s shared folders', function () {
    $target = gitProjectTarget(['versionable' => false]);
    $sha = headOf($target->project->local_path);

    $deployment = runDeploy($target);
    $rsync = lastRsyncOf($this);
    $sync = collect($this->ssh->calls)->last(fn (array $call) => $call['script']->name === 'deploy-sync-in-place')['script'];
    $staged = "/opt/srv/demo/.deploy/incoming/{$deployment->release->name}";

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded, (string) $deployment->outcome)
        ->and(stepMap($deployment))->toMatchArray(['shared' => 'skipped', 'activate' => 'skipped', 'prune' => 'skipped'])
        ->and($deployment->release->layout)->toBe(Release::IN_PLACE)
        ->and($deployment->outcome)->toBe(substr($sha, 0, 7).' is live in /opt/srv/demo.')
        // Staged beside the live files, linked to them where unchanged...
        ->and($rsync)->toContain('--filter=- /storage/')
        ->and($rsync)->toContain('--link-dest=/opt/srv/demo')
        ->and(end($rsync))->toBe("box:{$staged}/")
        // ...then synced into place, the shared folders and ignored files left alone.
        ->and(scriptVar($sync, 'TARGET'))->toBe('/opt/srv/demo')
        ->and(scriptVar($sync, 'RELEASE'))->toBe($deployment->release->name)
        ->and(explode("\n", (string) scriptVar($sync, 'FILTERS')))->toContain('- /.deploy/', '- /storage/', 'dir-merge,-r .gitignore')
        ->and($deployment->steps()->where('key', 'upload')->value('detail'))->toEndWith('3 files updated in place · 1 removed');
});

it('uploads the folder as it is when that strategy comes first', function () {
    $target = gitProjectTarget(['delivery_strategies' => [['key' => 'push_rsync', 'enabled' => true], ['key' => 'push_archive', 'enabled' => true]]]);

    $deployment = runDeploy($target);
    $rsync = lastRsyncOf($this);

    expect($deployment->strategy)->toBe('push_rsync')
        ->and($rsync)->toContain('--filter=:- .gitignore')->not->toContain('--checksum')
        ->and($rsync[count($rsync) - 2])->toBe(realpath($target->project->local_path).'/')
        ->and(is_dir($target->project->local_path))->toBeTrue();
});

it('says why when nothing can deliver the project', function () {
    // A GitHub project with no token that reaches its owner, and server git off.
    $deployment = runDeploy(demoTarget());

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->outcome)->toStartWith('Nothing can deliver this project right now.')
        ->and($deployment->outcome)->toContain('No GitHub token reaches KielD-01')
        ->and(stepMap($deployment))->toMatchArray(['resolve' => 'failed', 'preflight' => 'skipped', 'unlock' => 'skipped'])
        ->and(Release::query()->count())->toBe(0);

    $this->ssh->assertNothingRan();
});

it('runs host hooks in the release folder and stops at one that fails', function () {
    $target = gitProjectTarget();
    $target->project->hooks()->create(['phase' => 'release', 'name' => 'Migrate', 'command' => 'php artisan migrate --force', 'run_in' => 'host', 'timeout' => 300, 'position' => 1]);
    fakeDeployScripts($this->ssh, ['deploy-hook' => new SshResult(1, "SQLSTATE[08006] connection refused\n", '', 1.0)]);

    $deployment = runDeploy($target);

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->outcome)->toBe('Migrate failed with exit code 1.')
        ->and(stepMap($deployment))->toMatchArray(['hooks_release' => 'failed', 'activate' => 'skipped', 'rollback' => 'skipped', 'unlock' => 'succeeded'])
        ->and($deployment->release->status)->toBe(ReleaseStatus::Failed)
        ->and($target->refresh()->current_release_id)->toBeNull();

    $hook = collect($this->ssh->calls)->first(fn (array $call) => $call['script']->name === 'deploy-hook');
    expect($hook['script']->render())->toContain(base64_encode($deployment->release->path), base64_encode('php artisan migrate --force'));
});

it('masks secrets in what a failed step says, not only in the log', function () {
    $target = gitProjectTarget();
    $target->project->envFiles()->create(['path' => '.env', 'role' => 'app', ...ProjectEnvFile::contentAttributes("DB_PASSWORD=s3cret-pa55word\n")]);
    fakeDeployScripts($this->ssh, ['deploy-activate' => new SshResult(1, '', "fatal: auth s3cret-pa55word refused by https://bot:ghp_abcdefghijklmnopqrstuvwxyz12@github.com/x\n", 1.0)]);

    $deployment = runDeploy($target);
    $step = $deployment->steps()->where('key', 'activate')->sole();

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->outcome)->toContain('fatal: auth')
        ->and($deployment->outcome)->not->toContain('s3cret-pa55word')
        ->and($deployment->outcome)->not->toContain('ghp_')
        ->and($step->detail)->toContain('fatal: auth')
        ->and($step->detail)->not->toContain('s3cret-pa55word')
        ->and($step->detail)->not->toContain('ghp_');
});

it('rolls back to an older release that’s still on the server', function () {
    $target = gitProjectTarget();
    $first = runDeploy($target)->release;
    $second = runDeploy($target)->release;

    $rollback = runDeploy($target, DeploymentKind::Rollback, ['release_id' => $first->id]);

    expect($rollback->status)->toBe(DeploymentStatus::Succeeded, (string) $rollback->outcome)
        ->and($rollback->outcome)->toBe("Release {$first->name} is live again on {$target->server->name}.")
        ->and(stepMap($rollback))->toMatchArray(['pick' => 'succeeded', 'activate' => 'succeeded', 'rollback' => 'skipped'])
        ->and($rollback->commit_sha)->toBe($first->commit_sha)
        ->and($target->refresh()->current_release_id)->toBe($first->id)
        ->and($target->previous_release_id)->toBe($second->id);
});

it('removes releases beyond the keep count, never the one a rollback needs', function () {
    $target = gitProjectTarget(['keep_releases' => 2]);
    $old = collect(['20260901-100000', '20260902-100000', '20260903-100000'])
        ->map(fn (string $name) => Release::factory()->create(['target_id' => $target->id, 'name' => $name]));
    $target->update(['current_release_id' => $old->last()->id]);

    $deployment = runDeploy($target);

    $prune = collect($this->ssh->calls)->first(fn (array $call) => $call['script']->name === 'deploy-prune');
    expect($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and($prune['script']->render())->toContain(base64_encode("20260902-100000\n20260901-100000"))
        ->and($deployment->steps()->where('key', 'prune')->value('detail'))->toBe('removed 2 · 2 kept')
        ->and($deployment->steps()->where('key', 'prune')->value('label'))->toBe('Prune to 2 releases')
        ->and($old->map(fn (Release $release) => $release->refresh()->status)->all())
        ->toBe([ReleaseStatus::Pruned, ReleaseStatus::Pruned, ReleaseStatus::Ready]);
});
