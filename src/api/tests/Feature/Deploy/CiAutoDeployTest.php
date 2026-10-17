<?php

use App\Actions\Projects\PollCi;
use App\Enums\CiState;
use App\Jobs\RefreshDrift;
use App\Models\Credential;
use App\Models\Deployment;
use App\Models\Release;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

// Deployment #254: main's head was 602b3b8, already live, and GitHub's list
// of the branch's runs came back with August's run #3 for 47418c4 first.
beforeEach(function () {
    Queue::fake();
    Credential::factory()->create();

    $this->head = '602b3b84479ef017ea4199f2cd5ac222f15cd796';
    $this->old = '47418c453672b0165a8f65a2cb34928209305cbc';
    $this->listed = [];
    $this->byCommit = [];
    $this->compare = [];
    $this->target = demoTarget(['github_owner' => 'KielD-01', 'github_repo' => 'demo', 'auto_deploy' => true]);

    fakeBranchCi($this);
});

/**
 * GitHub for KielD-01/demo, steered by the test: main's head, the branch's
 * runs list, the runs asked for by commit, and "base...head" compare answers
 * (a status, or an HTTP error code).
 */
function fakeBranchCi(object $test): void
{
    Http::preventStrayRequests();

    Http::fake(function (Request $request) use ($test) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $repo = '/repos/KielD-01/demo';

        if ($path === "{$repo}/git/ref/heads/main") {
            return Http::response(['object' => ['sha' => $test->head]]);
        }

        if ($path === "{$repo}/actions/runs" && isset($request['head_sha'])) {
            return Http::response(['workflow_runs' => $test->byCommit[$request['head_sha']] ?? []]);
        }

        if ($path === "{$repo}/actions/runs") {
            return Http::response(['workflow_runs' => $test->listed], 200, ['ETag' => 'W/"'.md5(json_encode($test->listed)).'"']);
        }

        if (str_starts_with($path, "{$repo}/compare/")) {
            $answer = $test->compare[substr($path, strlen("{$repo}/compare/"))] ?? 404;

            return is_int($answer) ? Http::response(['message' => 'No answer'], $answer) : Http::response(['status' => $answer]);
        }

        return null;
    });
}

function deployRun(int $number, string $sha): array
{
    return workflowRun([
        'id' => $number, 'name' => 'Deploy', 'path' => '.github/workflows/deploy.yml', 'run_number' => $number, 'head_sha' => $sha,
    ]);
}

function liveRelease(object $test, string $sha): void
{
    $release = Release::factory()->create(['target_id' => $test->target->id, 'commit_sha' => $sha]);
    $test->target->update(['current_release_id' => $release->id]);
}

it('deploys the branch head when GitHub lists an old run first', function () {
    $this->listed = [deployRun(3, $this->old), deployRun(45, $this->head)];

    app(PollCi::class)($this->target->project);

    $project = $this->target->project->refresh();
    expect($project->ci_state)->toBe(CiState::Passing)
        ->and($project->ci_run['sha'])->toBe($this->head)
        ->and($project->ci_run['number'])->toBe(45)
        ->and(Deployment::query()->sole()->options)->toBe(['commit' => $this->head]);
});

it('measures the drift again when a poll finds something new', function () {
    $this->listed = [deployRun(45, $this->head)];

    app(PollCi::class)($this->target->project);

    Queue::assertPushed(RefreshDrift::class, 1);
    Queue::assertPushed(RefreshDrift::class, fn ($job) => $job->projectId === $this->target->project_id);
});

it('asks for the head’s own runs when the list leaves them out', function () {
    $this->listed = [deployRun(3, $this->old)];
    $this->byCommit[$this->head] = [deployRun(45, $this->head)];

    app(PollCi::class)($this->target->project);

    expect($this->target->project->refresh()->ci_run['number'])->toBe(45)
        ->and(Deployment::query()->sole()->options)->toBe(['commit' => $this->head]);
});

it('deploys nothing while the head has no green run, whatever else the list holds', function () {
    $this->listed = [deployRun(3, $this->old)];

    app(PollCi::class)($this->target->project);

    expect($this->target->project->refresh()->ci_state)->toBe(CiState::Unknown)
        ->and($this->target->project->ci_run)->toBeNull()
        ->and(Deployment::query()->count())->toBe(0);
});

it('doesn’t take an empty runs list for a branch without workflows', function () {
    $this->listed = [];
    $this->byCommit[$this->head] = [deployRun(45, $this->head)];
    liveRelease($this, $this->head);

    app(PollCi::class)($this->target->project);

    expect($this->target->project->refresh()->ci_state)->toBe(CiState::Passing)
        ->and(Deployment::query()->count())->toBe(0);
});

it('never deploys a commit the live release is already past', function (string $status, bool $deploys) {
    $live = str_repeat('c', 40);
    liveRelease($this, $live);
    $this->listed = [deployRun(45, $this->head)];
    $this->compare["{$live}...{$this->head}"] = $status;

    app(PollCi::class)($this->target->project);

    expect(Deployment::query()->count())->toBe($deploys ? 1 : 0);
})->with([
    'ahead' => ['ahead', true],
    'diverged (the branch was rewritten)' => ['diverged', true],
    'behind' => ['behind', false],
    'identical' => ['identical', false],
]);

it('leaves a target alone when its live release already is the head', function () {
    liveRelease($this, $this->head);
    $this->listed = [deployRun(45, $this->head)];

    app(PollCi::class)($this->target->project);

    expect(Deployment::query()->count())->toBe(0);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/compare/'));
});

it('asks again on the next poll when GitHub can’t compare', function () {
    $live = str_repeat('c', 40);
    liveRelease($this, $live);
    $this->listed = [deployRun(45, $this->head)];
    $this->compare["{$live}...{$this->head}"] = 502;

    app(PollCi::class)($this->target->project);

    expect(Deployment::query()->count())->toBe(0)
        ->and($this->target->project->refresh()->ci_etag)->toBeNull();

    $this->compare["{$live}...{$this->head}"] = 'ahead';
    app(PollCi::class)($this->target->project);

    expect(Deployment::query()->sole()->options)->toBe(['commit' => $this->head]);
});

it('never auto-deploys into a folder an earlier deployment still runs from', function () {
    $this->listed = [deployRun(45, $this->head)];
    $this->target->update(['folder_scan' => ['foreign' => ['count' => 4, 'names' => ['.env'], 'seen_at' => now()->toIso8601String()]]]);
    Log::spy();

    app(PollCi::class)($this->target->project);

    expect($this->target->project->refresh()->ci_state)->toBe(CiState::Passing)
        ->and(Deployment::query()->count())->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'CI auto-deploy refused'
        && str_contains($context['reason'] ?? '', 'still runs from an earlier deployment'));
});
