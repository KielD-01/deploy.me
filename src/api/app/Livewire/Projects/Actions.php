<?php

namespace App\Livewire\Projects;

use App\Actions\Deployments\StartAction;
use App\Deploy\Actions\ActionInputs;
use App\Deploy\Actions\RepoActions;
use App\Enums\AuditAction;
use App\Enums\DeploymentKind;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectAction;
use App\Models\Target;
use App\Support\Audit;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * The project's actions: the ones its repository keeps in .deployme/actions
 * (read-only here) and the ones made on the page, when each runs, how its
 * last run went, and the latest runs. Running one opens the run dialog.
 */
class Actions extends Component
{
    public const RECENT = 8;

    #[Locked]
    public int $projectId;

    // The action whose definition and runs the Console theme shows beside the list.
    #[Url(as: 'action')]
    public ?string $selected = null;

    public ?string $notice = null;

    public ?string $problem = null;

    public function mount(int $projectId): void
    {
        $this->projectId = $projectId;
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()->findOrFail($this->projectId);
    }

    // Runs start and finish while the page is open.
    #[On('echo-private:projects.{projectId},.updated')]
    public function refreshRuns(): void {}

    public function select(string $key): void
    {
        $this->selected = ProjectAction::query()->where('project_id', $this->projectId)->where('key', $key)->value('key');
    }

    /**
     * Reads .deployme/actions again at the head of the branch.
     */
    public function reload(RepoActions $repo): void
    {
        $this->reset('notice', 'problem');

        try {
            $this->notice = RepoActions::summary($repo->reload($this->project()));
        } catch (RuntimeException $e) {
            $this->problem = $e->getMessage();
        }
    }

    public function delete(int $id): void
    {
        $this->reset('notice', 'problem');
        $action = ProjectAction::query()->where('project_id', $this->projectId)->findOrFail($id);

        if ($action->fromRepo()) {
            $this->problem = "{$action->name} comes from the repository: remove {$action->file} there, then reload.";

            return;
        }

        $action->delete();
        Audit::record(AuditAction::ActionRemoved, ['subject' => $action->name, 'project_id' => $action->project_id, 'detail' => 'its runs stay in the history']);
        $this->notice = "Removed {$action->name}.";

        if ($this->selected === $action->key) {
            $this->selected = null;
        }
    }

    public function render(): View
    {
        $project = $this->project();
        $actions = $project->actions()->with(['latestRun.server'])->get();
        $selected = $actions->firstWhere('key', $this->selected) ?? $actions->first();

        return view('livewire.projects.actions', [
            'project' => $project,
            'actions' => $actions,
            'current' => $selected,
            'selectedRuns' => $selected !== null ? $selected->runs()->with(['server', 'user'])->limit(self::RECENT)->get() : collect(),
            'yaml' => $selected?->definition() !== null ? Yaml::dump($selected->definition()->toYaml(), 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK) : null,
            'recent' => Deployment::query()->where('project_id', $project->id)->where('kind', DeploymentKind::Action)
                ->with(['server', 'user'])->latest('id')->limit(self::RECENT)->get(),
            'fromRepo' => $actions->where('source', ProjectAction::REPO)->count(),
            'hasTargets' => Target::query()->active()->where('project_id', $project->id)->exists(),
        ]);
    }

    /**
     * How a run started, as the lists say it: "by you", "schedule", "after #20".
     */
    public static function startedBy(Deployment $run): string
    {
        return match ($run->trigger) {
            'manual' => $run->user_id !== null ? 'by you' : 'by hand',
            'console' => 'console',
            'mcp' => 'MCP',
            'schedule' => 'schedule',
            'after_deploy' => 'after run #'.($run->options['after'] ?? '?'),
            default => StartAction::why($run->trigger, $run->options ?? []),
        };
    }

    /**
     * index=all fresh=yes (a secret as its mask).
     */
    public static function inputsLine(Deployment $run): string
    {
        return ActionInputs::line((array) ($run->options['shown'] ?? []));
    }
}
