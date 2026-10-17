<?php

namespace App\Livewire\Deployments;

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Deployments')]
class Index extends Component
{
    use WithPagination;

    public const FILTERS = ['all' => 'All', 'running' => 'Running', 'failed' => 'Failed', 'rolled_back' => 'Rolled back'];

    #[Url]
    public string $status = 'all';

    #[Url]
    public string $project = '';

    #[Url]
    public string $server = '';

    // Every run's progress re-renders the list.
    #[On('echo-private:deployments,.updated')]
    public function refreshRuns(): void {}

    public function updating(string $property): void
    {
        if (in_array($property, ['status', 'project', 'server'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $runs = Deployment::query()
            ->with(['project', 'server'])
            ->when($this->status === 'running', fn ($query) => $query->whereIn('status', DeploymentStatus::ACTIVE))
            ->when($this->status === 'failed', fn ($query) => $query->whereIn('status', [DeploymentStatus::Failed, DeploymentStatus::Stalled]))
            ->when($this->status === 'rolled_back', fn ($query) => $query->where('status', DeploymentStatus::RolledBack))
            ->when($this->project !== '', fn ($query) => $query->where('project_id', (int) $this->project))
            ->when($this->server !== '', fn ($query) => $query->where('server_id', (int) $this->server))
            ->latest('id')
            ->paginate(50);

        return view('livewire.deployments.index', [
            'runs' => $runs,
            'filters' => self::FILTERS,
            'filtered' => $this->status !== 'all' || $this->project !== '' || $this->server !== '',
            'projects' => Project::query()->active()->orderBy('name')->get(['id', 'name']),
            'servers' => Server::query()->active()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
