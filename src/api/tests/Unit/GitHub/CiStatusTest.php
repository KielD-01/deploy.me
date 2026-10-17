<?php

use App\Enums\CiState;
use App\Platform\GitHub\CiStatus;

it('only counts the runs for the newest commit', function () {
    $ci = CiStatus::fromRuns([
        workflowRun(['id' => 3, 'status' => 'in_progress', 'conclusion' => null]),
        workflowRun(['id' => 2, 'workflow_id' => 2]),
        workflowRun(['id' => 1, 'head_sha' => 'old', 'conclusion' => 'failure']),
    ]);

    expect($ci['state'])->toBe(CiState::Running)
        ->and($ci['run'])->toMatchArray(['id' => 3, 'workflow' => 'ci.yml', 'number' => 7]);
});

it('fails when any workflow failed for the newest commit', function () {
    $ci = CiStatus::fromRuns([
        workflowRun(['id' => 2, 'workflow_id' => 2, 'conclusion' => 'failure']),
        workflowRun(['id' => 1]),
    ]);

    expect($ci['state'])->toBe(CiState::Failing)->and($ci['run']['id'])->toBe(2);
});

it('passes when a re-run of a failed workflow passed', function () {
    $ci = CiStatus::fromRuns([
        workflowRun(['id' => 3]),
        workflowRun(['id' => 2, 'conclusion' => 'failure']),
    ]);

    expect($ci['state'])->toBe(CiState::Passing);
});

it('says so when nothing has run', function () {
    expect(CiStatus::fromRuns([]))->toBe(['state' => CiState::NoWorkflows, 'run' => null]);
});
