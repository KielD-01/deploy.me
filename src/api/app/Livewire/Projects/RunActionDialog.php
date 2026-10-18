<?php

namespace App\Livewire\Projects;

use App\Actions\Deployments\StartAction;
use App\Deploy\DeploymentBusy;
use App\Deploy\DeploymentRefused;
use App\Livewire\Concerns\ConfirmsIdentity;
use App\Models\Project;
use App\Models\ProjectAction;
use App\Models\Target;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Runs an action: which target, the inputs it asks for, and what will run
 * where before it does. An action that asks for a passkey asks before the
 * dialog opens.
 */
class RunActionDialog extends Component
{
    use ConfirmsIdentity;

    #[Locked]
    public int $projectId;

    public bool $open = false;

    #[Locked]
    public ?int $actionId = null;

    public ?int $targetId = null;

    /** @var array<string, string|bool> by input name */
    public array $values = [];

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

    /**
     * @param  array<string, mixed>  $inputs  values to start from (Run again)
     */
    #[On('open-action-dialog')]
    public function openFor(int $actionId, ?int $targetId = null, array $inputs = []): void
    {
        $action = ProjectAction::query()->where('project_id', $this->projectId)->find($actionId);
        $definition = $action?->definition();

        if ($action === null || $definition === null || ! $definition->manual()) {
            return;
        }

        if ($definition->passkey() && ! $this->confirmIdentity(route('projects.show', ['project' => $this->project(), 'tab' => 'actions']))) {
            return;
        }

        $targets = $definition->allowedTargets($this->project());

        $this->reset('problem');
        $this->actionId = $action->id;
        $this->targetId = ($targets->firstWhere('id', $targetId) ?? $targets->first())?->id;
        $this->values = [];

        foreach ($definition->inputs() as $input) {
            $given = $input['type'] === 'secret' ? null : ($inputs[$input['name']] ?? null);
            $value = $given ?? $input['default'];
            $this->values[$input['name']] = $input['type'] === 'boolean'
                ? $value === true || in_array(strtolower((string) $value), ['1', 'y', 'yes', 'true', 'on'], true)
                : (is_scalar($value) ? (string) $value : '');
        }

        $this->open = true;
    }

    public function close(): void
    {
        $this->reset('open', 'actionId', 'targetId', 'values', 'problem');
    }

    public function pickTarget(int $id): void
    {
        $this->targetId = $id;
    }

    public function pick(string $input, string $value): void
    {
        if (array_key_exists($input, $this->values)) {
            $this->values[$input] = $value;
        }
    }

    public function run(StartAction $start): void
    {
        $this->reset('problem');
        $action = $this->action();
        $target = $this->target();

        if ($action === null || $target === null) {
            $this->problem = 'Pick a target to run it on.';

            return;
        }

        try {
            $run = $start($action, $target, $this->values, auth()->user(), 'manual', identityConfirmed: $this->identityConfirmed());
        } catch (DeploymentBusy|DeploymentRefused $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->values = [];
        $this->redirectRoute('deployments.show', $run, navigate: true);
    }

    private function action(): ?ProjectAction
    {
        return $this->actionId !== null ? ProjectAction::query()->where('project_id', $this->projectId)->find($this->actionId) : null;
    }

    private function target(): ?Target
    {
        return $this->targetId !== null ? Target::query()->active()->where('project_id', $this->projectId)->with(['server', 'currentRelease', 'project'])->find($this->targetId) : null;
    }

    public function render(): View
    {
        $action = $this->open ? $this->action() : null;
        $definition = $action?->definition();
        $target = $this->open ? $this->target() : null;

        return view('livewire.projects.run-action-dialog', [
            'action' => $action,
            'definition' => $definition,
            'targets' => $definition?->allowedTargets($this->project()) ?? collect(),
            'target' => $target,
            'folder' => $target !== null && $target->isLive() ? StartAction::folder($target) : null,
        ]);
    }
}
