<?php

namespace App\Jobs;

use App\Actions\Projects\PollCi;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollCiStatus implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(public int $projectId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->projectId;
    }

    public function handle(PollCi $poll): void
    {
        $project = Project::query()->active()->find($this->projectId);

        if ($project !== null) {
            $poll($project);
        }
    }
}
