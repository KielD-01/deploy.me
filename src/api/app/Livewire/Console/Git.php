<?php

namespace App\Livewire\Console;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use App\Models\Target;
use App\Support\BranchCommits;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A git pane: a project's branch as `git log --graph`, each commit tagged
 * with where it's live, the releases made from it and the runs deploying it.
 * Without a project named it shows the one deployed most recently.
 */
class Git extends Component
{
    public ?string $pane = null;

    public ?string $arg = null;

    public ?int $projectId = null;

    public function mount(?string $pane = null, ?string $arg = null): void
    {
        $this->pane = $pane;
        $this->arg = $arg;
        $this->projectId = $arg !== null
            ? Project::query()->active()->where('slug', $arg)->value('id')
            : (Deployment::query()->whereHas('project', fn ($query) => $query->whereNull('archived_at'))->latest('id')->value('project_id')
                ?? Project::query()->active()->orderBy('name')->value('id'));
    }

    #[On('echo-private:deployments,.updated')]
    public function refresh(): void {}

    public function render(): View
    {
        $project = $this->projectId !== null ? Project::query()->find($this->projectId) : null;
        $tags = [];

        if ($project !== null) {
            $targets = $project->targets()->active()->with(['server', 'currentRelease'])->get();

            foreach ($targets as $target) {
                /** @var Target $target */
                if ($sha = $target->currentRelease?->commit_sha) {
                    $tags[$sha][] = [$target->server->slug.' ● live', 't-ok'];
                }
            }

            Release::query()->whereIn('target_id', $targets->modelKeys())->whereNotNull('commit_sha')->latest('id')->limit(30)->get()
                ->each(function (Release $release) use (&$tags, $targets) {
                    if ($targets->contains('current_release_id', $release->id)) {
                        return;
                    }
                    $tags[$release->commit_sha][] = [$release->name, $release->status->value === 'failed' ? 't-warn' : 't-v'];
                });

            Deployment::query()->where('project_id', $project->id)->whereIn('status', DeploymentStatus::ACTIVE)->whereNotNull('commit_sha')->with('server')->get()
                ->each(function (Deployment $run) use (&$tags) {
                    $tags[$run->commit_sha][] = ['#'.$run->id.' → '.$run->server?->slug, 't-run'];
                });
        }

        return view('livewire.console.git', [
            'project' => $project,
            'commits' => $project !== null ? BranchCommits::recent($project, 14) : [],
            'tags' => $tags,
        ]);
    }
}
