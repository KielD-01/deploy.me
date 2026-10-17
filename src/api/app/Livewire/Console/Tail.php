<?php

namespace App\Livewire\Console;

use App\Enums\DeploymentStatus;
use App\Enums\StepStatus;
use App\Livewire\Console\Concerns\LoadsAfterThePage;
use App\Models\Deployment;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * A tail pane: one run's steps as a progress line, and its log as it's
 * written (resources/js/deploy-log.js, the same as the run page). Without a
 * run named it follows the newest one, switching when another starts.
 */
class Tail extends Component
{
    use LoadsAfterThePage;

    #[Locked]
    public ?string $pane = null;

    #[Locked]
    public ?string $arg = null;

    #[Locked]
    public ?int $deploymentId = null;

    public function mount(?string $pane = null, ?string $arg = null): void
    {
        $this->pane = $pane;
        $this->arg = $arg;
        $this->deploymentId = $this->pick();
    }

    #[On('echo-private:deployments,.updated')]
    public function refresh(): void
    {
        if ($this->arg === null) {
            $this->deploymentId = $this->pick();
        }
    }

    /**
     * @return list<array{0: int, 1: string, 2: ?string, 3: string, 4: string}>
     */
    #[Renderless]
    public function linesAfter(int $seq): array
    {
        $run = $this->deploymentId !== null ? Deployment::query()->find($this->deploymentId) : null;

        return $run?->linesForPane($seq) ?? [];
    }

    private function pick(): ?int
    {
        if ($this->arg !== null) {
            return Deployment::query()->find((int) ltrim($this->arg, '#'))?->id;
        }

        return Deployment::query()->whereIn('status', DeploymentStatus::ACTIVE)->latest('id')->value('id')
            ?? Deployment::query()->latest('id')->value('id');
    }

    public function render(): View
    {
        $run = $this->deploymentId !== null ? Deployment::query()->with(['project', 'server', 'steps'])->find($this->deploymentId) : null;

        return view('livewire.console.tail', [
            'run' => $run,
            'segments' => $run?->steps->map(fn ($step) => [
                'name' => $step->label,
                'tone' => match ($step->status) {
                    StepStatus::Succeeded => 'bg-success',
                    StepStatus::Running => 'bg-primary',
                    StepStatus::Failed => 'bg-error',
                    StepStatus::Skipped, StepStatus::Cancelled => 'bg-base-300 opacity-50',
                    default => 'bg-base-300',
                },
            ])->all() ?? [],
            'current' => $run?->steps->firstWhere('status', StepStatus::Running) ?? $run?->steps->last(fn ($step) => $step->status !== StepStatus::Pending),
        ]);
    }
}
