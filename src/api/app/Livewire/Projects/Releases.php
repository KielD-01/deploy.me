<?php

namespace App\Livewire\Projects;

use App\Actions\Deployments\StartDeployment;
use App\Deploy\DeploymentBusy;
use App\Enums\DeploymentKind;
use App\Enums\ReleaseStatus;
use App\Models\Project;
use App\Models\Release;
use App\Models\Target;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The releases on each of a project's targets: what's live, what a rollback
 * can switch to, and what's been pruned.
 */
class Releases extends Component
{
    // Enough history for a page; older ones live on in the deployments list.
    public const SHOWN = 50;

    #[Locked]
    public int $projectId;

    public ?int $targetId = null;

    public ?string $problem = null;

    public function mount(int $projectId): void
    {
        $this->projectId = $projectId;
        $this->targetId = $this->project->targets()->active()->orderBy('id')->value('id');
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()->findOrFail($this->projectId);
    }

    // Runs add releases and settle them as they go.
    #[On('echo-private:projects.{projectId},.updated')]
    public function refreshReleases(): void {}

    public function pickTarget(int $id): void
    {
        $this->targetId = $this->project->targets()->active()->findOrFail($id)->id;
        $this->reset('problem');
    }

    public function rollBack(int $releaseId, StartDeployment $start): void
    {
        $release = $this->release($releaseId);

        if (! $release->canRollBackTo($this->project)) {
            $this->problem = $this->project->versionable
                ? "Release {$release->name} isn’t on the server any more."
                : "Release {$release->name} didn’t record which images it ran, so there’s nothing to put back.";

            return;
        }

        $this->startRun($release->target, DeploymentKind::Rollback, ['release_id' => $release->id], $start);
    }

    public function redeploy(int $releaseId, StartDeployment $start): void
    {
        $release = $this->release($releaseId);

        if ($release->commit_sha === null) {
            $this->problem = 'That deploy didn’t come from a commit, so there’s nothing to deploy again.';

            return;
        }

        $this->startRun($release->target, DeploymentKind::Redeploy, ['commit' => $release->commit_sha], $start);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function startRun(Target $target, DeploymentKind $kind, array $options, StartDeployment $start): void
    {
        try {
            $deployment = $start($target, $kind, auth()->user(), $options);
        } catch (DeploymentBusy $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->redirectRoute('deployments.show', $deployment, navigate: true);
    }

    private function release(int $id): Release
    {
        return Release::query()
            ->whereKey($id)
            ->whereHas('target', fn ($query) => $query->where('project_id', $this->projectId)->whereNull('archived_at'))
            ->with('target')
            ->firstOrFail();
    }

    public function render(): View
    {
        $targets = $this->project->targets()->active()->with('server')->orderBy('id')->get();
        $target = $targets->firstWhere('id', $this->targetId) ?? $targets->first();
        $releases = $target !== null ? $target->releases()->with('deployment')->limit(self::SHOWN)->get() : collect();

        return view('livewire.projects.releases', [
            'targets' => $targets,
            'target' => $target,
            'releases' => $releases,
            'kept' => $releases->filter(fn (Release $release) => $release->inReleasesFolder() && $release->status === ReleaseStatus::Ready)->count(),
            'current' => $target !== null ? $releases->firstWhere('id', $target->current_release_id) : null,
        ]);
    }
}
