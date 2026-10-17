<?php

namespace App\Livewire;

use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Enums\TargetHealth;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerSnapshot;
use App\Models\Target;
use App\Support\Drift;
use App\Support\ViewerTimezone;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Everything at a glance: servers, runs, health checks, and what's deployed
 * next to what's newest. Probes and runs refresh it as they land; it also
 * polls, for health checks and CI.
 */
#[Title('Dashboard')]
class Dashboard extends Component
{
    #[On('echo-private:servers,.probed')]
    public function refreshServers(): void {}

    #[On('echo-private:deployments,.updated')]
    public function refreshRuns(): void {}

    #[On('echo-private:projects,.ci.updated')]
    public function refreshCi(): void {}

    #[On('echo-private:projects,.health.updated')]
    public function refreshHealth(): void {}

    public function render(): View
    {
        $servers = Server::query()->active()->with('latestSnapshot')
            ->withCount(['targets' => fn ($query) => $query->whereNull('archived_at')])
            ->orderBy('name')
            ->get();

        $cpuHistory = ServerSnapshot::query()
            ->whereIn('server_id', $servers->modelKeys())
            ->where('captured_at', '>=', now()->subDay())
            ->orderBy('captured_at')
            ->get(['server_id', 'cpu_usage'])
            ->groupBy('server_id')
            ->map(fn ($rows) => $rows->pluck('cpu_usage')->all());

        $targets = Target::query()
            ->active()
            ->whereHas('project', fn ($query) => $query->whereNull('archived_at'))
            ->whereHas('server', fn ($query) => $query->whereNull('archived_at'))
            ->with(['project', 'server', 'currentRelease', 'activeDeployment', 'latestDeployment'])
            ->get()
            ->sortBy(fn (Target $target) => $target->project->name.'|'.$target->server->name)
            ->values();

        $checked = $targets->filter(fn (Target $target) => $target->health_checked_at !== null);
        $unhealthy = $checked->filter(fn (Target $target) => $target->health === TargetHealth::Unhealthy)->values();

        return view('livewire.dashboard', [
            'servers' => $servers,
            'cpuHistory' => $cpuHistory,
            'reachable' => $servers->filter(fn (Server $server) => $server->status !== ServerStatus::Offline)->count(),
            'projectCount' => Project::query()->active()->count(),
            // The viewer's today, not UTC's: the column holds UTC, so the boundary is converted back.
            'today' => Deployment::query()->where('created_at', '>=', now(ViewerTimezone::name())->startOfDay()->utc())->pluck('status'),
            'active' => Deployment::query()->whereIn('status', DeploymentStatus::ACTIVE)->with(['project', 'server'])->latest('id')->get(),
            'recent' => Deployment::query()->with(['project', 'server'])->latest('id')->limit(5)->get(),
            'checked' => $checked,
            'unhealthy' => $unhealthy,
            'troubledServers' => $servers->filter(fn (Server $server) => $server->status !== ServerStatus::Online)->values(),
            'failedTargets' => $targets
                ->filter(fn (Target $target) => $target->activeDeployment === null
                    && in_array($target->latestDeployment?->status, [DeploymentStatus::Failed, DeploymentStatus::Stalled], true))
                ->values(),
            'drift' => $targets->map(fn (Target $target) => ['target' => $target, ...Drift::for($target->project, $target->currentRelease?->commit_sha)]),
        ]);
    }
}
