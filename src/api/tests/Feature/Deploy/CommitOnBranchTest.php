<?php

use App\Actions\Deployments\StartDeployment;
use App\Deploy\Delivery\CommitOnBranch;
use App\Deploy\Pipeline\PipelineRunner;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Events\DeploymentLogged;
use App\Events\DeploymentUpdated;
use App\Mcp\ToolFailed;
use App\Mcp\Tools\Deploy as DeployTool;
use App\Models\Credential;
use App\Models\Deployment;
use App\Models\Target;
use App\Models\User;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\Testing\FakeSshClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

// An agent over MCP and CI's automatic deploys ship only commits on the
// project's branch; a person can pick any commit.
beforeEach(function () {
    config(['deployme.project_roots' => [sys_get_temp_dir()]]);
    User::factory()->create();
});

/**
 * Runs git in a throwaway repository (feature tests only: the real binary).
 */
function gitIn(string $repo, string ...$args): string
{
    return trim(Process::path($repo)->env([
        'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1',
        'GIT_AUTHOR_NAME' => 'Test', 'GIT_AUTHOR_EMAIL' => 'test@example.com',
        'GIT_COMMITTER_NAME' => 'Test', 'GIT_COMMITTER_EMAIL' => 'test@example.com',
    ])->run(['git', ...$args])->throw()->output());
}

/**
 * A local project on main with a commit of its own on a side branch.
 *
 * @return array{0: Target, 1: string, 2: string} the target, main's head, the side branch's commit
 */
function sideBranchTarget(): array
{
    $repo = tempGitRepo(['index.php' => 'v1']);
    $main = gitIn($repo, 'rev-parse', 'HEAD');
    gitIn($repo, 'checkout', '--quiet', '-b', 'experiment');
    commitFile($repo, 'index.php', 'v2');
    $side = gitIn($repo, 'rev-parse', 'HEAD');
    gitIn($repo, 'checkout', '--quiet', 'main');

    $target = demoTarget([
        'source' => 'local', 'local_path' => $repo, 'github_owner' => null, 'github_repo' => null,
        'delivery_strategies' => [['key' => 'push_archive', 'enabled' => true]],
    ]);

    return [$target, $main, $side];
}

it('tells a commit on the branch from one that isn’t, in the repository here', function () {
    [$target, $main, $side] = sideBranchTarget();
    commitFile($target->project->local_path, 'index.php', 'v3');
    $check = app(CommitOnBranch::class);

    expect($check->check($target->project, $main))->toBeTrue()
        ->and($check->check($target->project, substr($main, 0, 7)))->toBeTrue()
        ->and($check->check($target->project, $side))->toBeFalse()
        ->and($check->check($target->project, str_repeat('d', 40)))->toBeNull()
        ->and($check->check($target->project, 'main'))->toBeFalse();
});

it('refuses an agent a commit that isn’t on the branch, before anything is queued', function () {
    Queue::fake();
    [$target, $main, $side] = sideBranchTarget();

    expect(fn () => app(DeployTool::class)->call(['project' => 'demo', 'commit' => $side]))
        ->toThrow(ToolFailed::class, 'Commit '.substr($side, 0, 7).' isn’t on main, and a deploy an agent starts ships only commits on the project’s branch.');
    Queue::assertNothingPushed();

    // An earlier commit on the branch goes, and so does anything a person picks.
    expect(app(DeployTool::class)->call(['project' => 'demo', 'commit' => $main])['started'])->toBeTrue();
    Deployment::query()->delete();

    $console = app(DeployTool::class);
    $console->trigger = 'console';
    expect($console->call(['project' => 'demo', 'commit' => $side])['started'])->toBeTrue();
    Deployment::query()->delete();

    expect(app(StartDeployment::class)($target, DeploymentKind::Deploy, options: ['commit' => $side])->options)->toBe(['commit' => $side]);
});

it('asks GitHub when there’s no mirror yet', function (string $status, bool $on) {
    Queue::fake();
    Credential::factory()->create();
    $head = str_repeat('a', 40);
    $sha = str_repeat('b', 40);
    demoTarget(['github_owner' => 'KielD-01', 'github_repo' => 'demo']);

    Http::preventStrayRequests();
    Http::fake(fn (Request $request) => match ((string) parse_url($request->url(), PHP_URL_PATH)) {
        '/repos/KielD-01/demo/git/ref/heads/main' => Http::response(['object' => ['sha' => $head]]),
        "/repos/KielD-01/demo/compare/{$head}...{$sha}" => Http::response(['status' => $status]),
        default => null,
    });

    $call = fn () => app(DeployTool::class)->call(['project' => 'demo', 'commit' => $sha]);

    $on ? expect($call()['started'])->toBeTrue() : expect($call)->toThrow(ToolFailed::class, 'isn’t on main');
})->with([
    'behind the head' => ['behind', true],
    'the head' => ['identical', true],
    'ahead of it' => ['ahead', false],
    'another branch' => ['diverged', false],
]);

it('refuses a CI deploy of a commit that isn’t on the branch when it resolves the release', function (string $trigger, DeploymentStatus $status) {
    [$target, , $side] = sideBranchTarget();
    config(['deployme.sync.poll_ms' => 0, 'deployme.ssh.runtime_dir' => sys_get_temp_dir().'/deployme-branch-'.bin2hex(random_bytes(4))]);
    $this->app->instance(SshClient::class, fakeDeployScripts(new FakeSshClient));
    Event::fake([DeploymentUpdated::class, DeploymentLogged::class]);
    Process::fake(['*rsync*' => Process::result("Number of files: 4\nNumber of regular files transferred: 4\nTotal file size: 900 bytes\nTotal transferred file size: 900 bytes\n")]);

    $run = Deployment::factory()->create(['target_id' => $target->id, 'kind' => DeploymentKind::Deploy, 'trigger' => $trigger, 'options' => ['commit' => $side]]);
    app(PipelineRunner::class)->run($run);
    $run->refresh();

    expect($run->status)->toBe($status, (string) $run->outcome);

    if ($status === DeploymentStatus::Failed) {
        expect($run->outcome)->toStartWith('Commit '.substr($side, 0, 7).' isn’t on main, and a deploy CI starts ships only commits')
            ->and($run->steps()->where('key', 'resolve')->value('status')->value)->toBe('failed')
            ->and($run->release_id)->toBeNull();
    }
})->with([
    'CI' => ['ci', DeploymentStatus::Failed],
    'a person' => ['manual', DeploymentStatus::Succeeded],
]);
