<?php

namespace App\Livewire\Console;

use App\Enums\DeploymentStatus;
use App\Livewire\Console\Concerns\LoadsAfterThePage;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use App\Models\Target;
use App\Support\BranchCommits;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A git pane: a project's branch as `git log --graph`, each commit tagged
 * with where it's live, the releases made from it and the runs deploying it.
 * Without a project named it shows the one deployed most recently. On the
 * Console theme's project page ($page) it's the page's main pane: more
 * commits, CI on the commit it ran for, and a deploy button on each.
 */
class Git extends Component
{
    use LoadsAfterThePage;

    #[Locked]
    public ?string $pane = null;

    #[Locked]
    public ?string $arg = null;

    #[Locked]
    public ?int $projectId = null;

    #[Locked]
    public bool $page = false;

    public function mount(?string $pane = null, ?string $arg = null, bool $page = false): void
    {
        $this->pane = $pane;
        $this->arg = $arg;
        $this->page = $page;
        $this->projectId = $arg !== null
            ? Project::query()->active()->where('slug', $arg)->value('id')
            : (Deployment::query()->whereHas('project', fn ($query) => $query->whereNull('archived_at'))->latest('id')->value('project_id')
                ?? Project::query()->active()->orderBy('name')->value('id'));
    }

    #[On('echo-private:deployments,.updated')]
    public function refresh(): void {}

    // The page's CI check and settings save land here too.
    #[On('echo-private:projects,.ci.updated')]
    public function refreshCi(): void {}

    public function render(): View
    {
        $project = $this->projectId !== null ? Project::query()->find($this->projectId) : null;
        $tags = [];
        $targets = collect();

        if ($project !== null) {
            $targets = $project->targets()->active()->with(['server', 'currentRelease', 'previousRelease'])->get();

            foreach ($targets as $target) {
                /** @var Target $target */
                if ($sha = $target->currentRelease?->commit_sha) {
                    $tags[$sha][] = [$target->server->slug.' ● live', 't-ok'];
                }
            }

            // Where a rollback of each target would go.
            $rollbackTo = $this->page ? $targets
                ->filter(fn (Target $target) => $target->previousRelease?->canRollBackTo($project))
                ->mapWithKeys(fn (Target $target) => [$target->previous_release_id => $target->server->slug])
                : collect();

            Release::query()->whereIn('target_id', $targets->modelKeys())->whereNotNull('commit_sha')->latest('id')->limit(30)->get()
                ->each(function (Release $release) use (&$tags, $targets, $rollbackTo) {
                    if ($targets->contains('current_release_id', $release->id)) {
                        return;
                    }
                    $back = $rollbackTo->get($release->id);
                    $tags[$release->commit_sha][] = [$release->name.($back !== null ? ' · ↺ '.$back.' rolls back here' : ''), $release->status->value === 'failed' ? 't-warn' : 't-v'];
                });

            Deployment::query()->where('project_id', $project->id)->whereIn('status', DeploymentStatus::ACTIVE)->whereNotNull('commit_sha')->with('server')->get()
                ->each(function (Deployment $run) use (&$tags) {
                    $tags[$run->commit_sha][] = ['#'.$run->id.' → '.$run->server?->slug, 't-run'];
                });
        }

        return view($this->page ? 'livewire.console.git-page' : 'livewire.console.git', [
            'project' => $project,
            'commits' => $project !== null ? BranchCommits::recent($project, $this->page ? 30 : 14) : [],
            'tags' => $tags,
            'targets' => $targets,
        ]);
    }
}
