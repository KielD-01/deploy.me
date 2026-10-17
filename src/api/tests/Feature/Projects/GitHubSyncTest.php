<?php

use App\Actions\Projects\DispatchDueCiPolls;
use App\Actions\Projects\PollCi;
use App\Actions\Projects\PublishToGitHub;
use App\Enums\CiState;
use App\Events\ProjectCiUpdated;
use App\Jobs\PollCiStatus;
use App\Models\Credential;
use App\Models\Project;
use App\Platform\GitHub\GitHubException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Http::preventStrayRequests());

it('creates the repository, adds it as origin and pushes the branch', function () {
    config(['deployme.project_roots' => [sys_get_temp_dir()]]);
    $repo = tempGitRepo(['README.md' => "# Demo\n"]);
    $bare = sys_get_temp_dir().'/deployme-bare-'.bin2hex(random_bytes(4)).'.git';
    Process::run(['git', 'init', '--bare', '--quiet', $bare])->throw();

    // GitHub answers with a clone URL; here it's a local bare repository.
    Http::fake(['api.github.com/user/repos' => Http::response(
        ['name' => 'demo', 'owner' => ['login' => 'KielD-01'], 'clone_url' => $bare, 'html_url' => 'https://github.com/KielD-01/demo'],
        201,
    )]);

    $steps = [];
    $result = app(PublishToGitHub::class)->create($repo, Credential::factory()->create(), 'KielD-01', 'demo', true, function (string $step) use (&$steps) {
        $steps[] = $step;
    });

    $head = trim(Process::path($repo)->run(['git', 'rev-parse', 'HEAD'])->output());

    expect($result)->toMatchArray(['owner' => 'KielD-01', 'repo' => 'demo', 'remote' => 'origin', 'branch' => 'main'])
        ->and($steps)->toBe(['Creating KielD-01/demo on GitHub', 'Adding it as the origin remote', 'Pushing main to origin'])
        ->and(trim(Process::path($bare)->run(['git', 'rev-parse', 'refs/heads/main'])->output()))->toBe($head)
        ->and(trim(Process::path($repo)->run(['git', 'rev-parse', '--abbrev-ref', 'main@{upstream}'])->output()))->toBe('origin/main');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.github.com/user/repos'
        && $request['name'] === 'demo'
        && $request['private'] === true);
});

it('creates organization repositories under the organization', function () {
    config(['deployme.project_roots' => [sys_get_temp_dir()]]);
    $repo = tempGitRepo(['README.md' => "# Demo\n"]);
    Http::fake(['api.github.com/orgs/MR-Studio-KZ-UA/repos' => Http::response(['message' => 'Repository creation failed.', 'errors' => [['message' => 'name already exists on this account']]], 422)]);

    expect(fn () => app(PublishToGitHub::class)->create($repo, Credential::factory()->create(), 'MR-Studio-KZ-UA', 'demo', true, fn () => null))
        ->toThrow(GitHubException::class, 'name already exists on this account');
});

it('stores the CI state of the deploy branch and reuses the ETag', function () {
    Event::fake([ProjectCiUpdated::class]);
    $project = Project::factory()->create(['github_owner' => 'KielD-01', 'github_repo' => 'demo', 'credential_id' => Credential::factory()->create()->id]);

    Http::fake(['api.github.com/repos/KielD-01/demo/git/ref/heads/main' => Http::response(['object' => ['sha' => 'new']])]);
    Http::fakeSequence('api.github.com/repos/KielD-01/demo/actions/runs*')
        ->push(['workflow_runs' => [workflowRun(['conclusion' => 'failure'])]], 200, ['ETag' => 'W/"abc"'])
        ->push(null, 304);

    app(PollCi::class)($project);

    expect($project->refresh()->ci_state)->toBe(CiState::Failing)
        ->and($project->ci_etag)->toBe('W/"abc"')
        ->and($project->ci_run['number'])->toBe(7);
    Event::assertDispatched(ProjectCiUpdated::class, fn (ProjectCiUpdated $event) => $event->state === 'failing');

    app(PollCi::class)($project);

    expect($project->refresh()->ci_state)->toBe(CiState::Failing);
    Http::assertSent(fn ($request) => $request->hasHeader('If-None-Match', 'W/"abc"'));
    Event::assertDispatchedTimes(ProjectCiUpdated::class, 1);
});

it('says there’s no remote for a folder that isn’t on GitHub', function () {
    $project = Project::factory()->local('/tmp/app')->create();

    app(PollCi::class)($project);

    expect($project->refresh()->ci_state)->toBe(CiState::NoRemote);
});

it('queues CI polls only for GitHub projects that are due', function () {
    Queue::fake();
    $due = Project::factory()->create(['ci_checked_at' => null]);
    Project::factory()->create(['ci_checked_at' => now()]);
    Project::factory()->local('/tmp/app')->create();

    expect(app(DispatchDueCiPolls::class)())->toBe(1);

    Queue::assertPushed(PollCiStatus::class, fn (PollCiStatus $job) => $job->projectId === $due->id);
});
