<div class="flex flex-col gap-5" wire:poll.30s>
    @php
        use App\Enums\DeploymentStatus;
        use App\Enums\ServerStatus;
        use App\Models\ServerSnapshot;
        $unreachable = $servers->firstWhere('status', ServerStatus::Offline);
        $todayCount = fn (DeploymentStatus $status) => $today->filter(fn ($value) => $value === $status)->count();
        $passing = $checked->count() - $unhealthy->count();
        $attention = $troubledServers->count() + $unhealthy->count() + $failedTargets->count();
    @endphp

    <x-page-header title="Dashboard"
                   :subtitle="$servers->count().' '.Str::plural('server', $servers->count()).' · '.$projectCount.' '.Str::plural('project', $projectCount)">
        <x-slot:actions>
            <a href="{{ route('projects.import') }}" wire:navigate class="btn"><x-icon name="plus" /> Import project</a>
            <a href="{{ route('servers.create') }}" wire:navigate class="btn btn-primary"><x-icon name="server" /> Add server</a>
        </x-slot:actions>
    </x-page-header>

    @if ($servers->isEmpty())
        <x-empty-state icon="server" title="Add your first server">
            deploy.me reads the hosts in your ~/.ssh/config. Pick one, check the connection and choose where its projects should live.
            <x-slot:actions>
                <a href="{{ route('servers.create') }}" wire:navigate class="btn btn-primary"><x-icon name="plus" /> Add server</a>
            </x-slot:actions>
        </x-empty-state>
    @else
        <div class="grid grid-cols-2 xl:grid-cols-4 gap-4">
            <section class="card bg-base-100 border border-line p-4 flex flex-col gap-1">
                <span class="text-[13px] text-muted">Servers online</span>
                <span class="text-[22px] leading-7 font-semibold tabular-nums">{{ $reachable }} <span class="text-[15px] font-normal text-muted">/ {{ $servers->count() }}</span></span>
                <span class="text-xs text-muted truncate inline-flex items-center gap-1.5">
                    @if ($unreachable)
                        <span class="status status-error"></span>
                        {{ $unreachable->name }} unreachable{{ $unreachable->last_seen_at ? ' for '.$unreachable->last_seen_at->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true) : '' }}
                    @else
                        All reachable over SSH
                    @endif
                </span>
            </section>
            <section class="card bg-base-100 border border-line p-4 flex flex-col gap-1">
                <span class="text-[13px] text-muted">Deployments today</span>
                <span class="text-[22px] leading-7 font-semibold tabular-nums">{{ $today->count() }}</span>
                <span class="text-xs text-muted truncate">
                    @php
                        $parts = array_filter([
                            $todayCount(DeploymentStatus::Succeeded) ? $todayCount(DeploymentStatus::Succeeded).' succeeded' : null,
                            $todayCount(DeploymentStatus::RolledBack) ? $todayCount(DeploymentStatus::RolledBack).' rolled back' : null,
                            $todayCount(DeploymentStatus::Failed) ? $todayCount(DeploymentStatus::Failed).' failed' : null,
                            $active->count() ? $active->count().' running' : null,
                        ]);
                    @endphp
                    {{ $parts !== [] ? implode(' · ', $parts) : 'Nothing yet today' }}
                </span>
            </section>
            <section class="card bg-base-100 border border-line p-4 flex flex-col gap-1">
                <span class="text-[13px] text-muted">Running now</span>
                <span class="text-[22px] leading-7 font-semibold tabular-nums">{{ $active->count() }}</span>
                <span class="text-xs text-muted truncate">
                    @if ($first = $active->first())
                        <a href="{{ route('deployments.show', $first) }}" wire:navigate class="hover:underline">{{ $first->project->name }} → {{ $first->server->name }}</a>
                    @else
                        Nothing running
                    @endif
                </span>
            </section>
            <section class="card bg-base-100 border border-line p-4 flex flex-col gap-1">
                <span class="text-[13px] text-muted">Health checks passing</span>
                @if ($checked->isEmpty())
                    <span class="text-[22px] leading-7 font-semibold text-muted">—</span>
                @else
                    <span class="text-[22px] leading-7 font-semibold tabular-nums">{{ $passing }} <span class="text-[15px] font-normal text-muted">/ {{ $checked->count() }}</span></span>
                @endif
                <span class="text-xs text-muted truncate inline-flex items-center gap-1.5">
                    @if ($sick = $unhealthy->first())
                        <span class="status status-warning"></span> {{ $sick->project->name }} on {{ $sick->server->name }}
                    @elseif ($checked->isEmpty())
                        <span title="A deployed target is checked when it has a health URL, or a Docker stack deploy.me runs.">No checks yet</span>
                    @else
                        All passing
                    @endif
                </span>
            </section>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-4 items-start">
            <div class="xl:col-span-2 flex flex-col gap-3">
                <div class="flex items-center">
                    <h2 class="text-[15px] font-semibold">Servers</h2>
                    <a href="{{ route('servers.index') }}" wire:navigate class="ml-auto text-[13px] link link-hover">All servers</a>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach ($servers as $server)
                        @php
                            $snapshot = $server->latestSnapshot;
                            $offline = $server->status === ServerStatus::Offline;
                            $disk = $snapshot?->diskFor($server->default_deploy_path ?: '/');
                        @endphp
                        <article class="card bg-base-100 border border-line" wire:key="tile-{{ $server->id }}">
                            <div class="p-4 flex flex-col gap-3">
                                <div class="flex flex-col gap-0.5 min-w-0">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <span class="status status-{{ $server->status->tone() }}"></span>
                                        <a href="{{ route('servers.show', $server) }}" wire:navigate class="font-semibold truncate min-w-16 hover:underline">{{ $server->name }}</a>
                                        <span class="badge badge-sm shrink-0 {{ $server->env_tag->badgeClass() }}">{{ $server->env_tag->label() }}</span>
                                        <span class="ml-auto text-xs text-muted truncate">
                                            @if ($offline)
                                                last seen {{ $server->last_seen_at?->diffForHumans() ?? 'never' }}
                                            @else
                                                probed {{ $server->last_probe_at?->diffForHumans() ?? 'not yet' }}
                                            @endif
                                        </span>
                                    </div>
                                    <p class="mono text-muted truncate">{{ $server->connectionLabel() }}{{ isset($server->specs['os']) ? ' · '.$server->specs['os'] : '' }}</p>
                                </div>

                                @if ($offline)
                                    @php $next = $server->next_probe_at; @endphp
                                    <div role="alert" class="alert alert-soft alert-error text-[13px] py-2.5">
                                        <x-icon name="x-circle" />
                                        <span><span class="font-semibold">Unreachable.</span> {{ $server->status_reason }}
                                            Next check {{ $next === null || $next->isPast() ? 'any moment' : 'in '.max(1, (int) ceil(now()->diffInSeconds($next) / 60)).' min' }}.</span>
                                    </div>
                                @else
                                    @if ($server->status === ServerStatus::Degraded && $server->status_reason)
                                        <p class="text-xs text-warning inline-flex items-center gap-1"><x-icon name="alert" :size="12" /> {{ $server->status_reason }}</p>
                                    @endif
                                    <div class="flex flex-col gap-1.5">
                                        <x-usage-bar label="CPU" :percent="$snapshot?->cpu_usage" />
                                        <x-usage-bar label="Memory" :percent="$snapshot?->memoryUsedPercent()" />
                                        <x-usage-bar label="Disk" :percent="ServerSnapshot::diskUsedPercent($disk)" />
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <span class="text-xs text-muted">CPU · last 24 h</span>
                                        <x-sparkline :values="$cpuHistory[$server->id] ?? []" :height="36" />
                                    </div>
                                @endif
                            </div>
                            <footer class="flex items-center gap-1.5 px-4 py-2.5 border-t border-line text-xs text-muted">
                                <x-icon name="repo" :size="12" /> {{ $server->targets_count ? $server->targets_count.' '.Str::plural('project', $server->targets_count) : 'No projects yet' }}
                                <span class="ml-auto mono truncate">{{ $server->default_deploy_path ?? '—' }}</span>
                            </footer>
                        </article>
                    @endforeach
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-1 gap-4 items-start">
                <section class="card bg-base-100 border border-line">
                    <header class="flex items-center min-h-[52px] px-4 border-b border-line">
                        <h2 class="text-[15px] font-semibold">Needs attention</h2>
                    </header>
                    @foreach ($troubledServers as $server)
                        @php $offline = $server->status === ServerStatus::Offline; @endphp
                        <div class="flex gap-3 px-4 py-3 border-b border-line" wire:key="attention-server-{{ $server->id }}">
                            <x-icon :name="$offline ? 'x-circle' : 'alert'" @class(['mt-0.5 shrink-0', 'text-error' => $offline, 'text-warning' => ! $offline]) />
                            <div class="flex flex-col gap-0.5 min-w-0">
                                <span class="font-semibold">{{ $server->name }} {{ $offline ? 'is unreachable' : 'needs a look' }}</span>
                                @if ($server->status_reason)
                                    <span class="text-[13px] text-muted">{{ $server->status_reason }}</span>
                                @endif
                                <a href="{{ route('servers.show', $server) }}" wire:navigate class="text-[13px] link link-hover self-start">Open server</a>
                            </div>
                        </div>
                    @endforeach
                    @foreach ($unhealthy as $target)
                        <div class="flex gap-3 px-4 py-3 border-b border-line" wire:key="attention-health-{{ $target->id }}">
                            <x-icon name="globe" class="mt-0.5 shrink-0 text-warning" />
                            <div class="flex flex-col gap-0.5 min-w-0">
                                <span class="font-semibold">{{ $target->project->name }} health check failing</span>
                                <span class="text-[13px] text-muted">
                                    {{ $target->health_detail }} on {{ $target->server->name }}{{ $target->unhealthy_since ? ' for '.$target->unhealthy_since->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true) : '' }}.
                                </span>
                                <a href="{{ route('projects.show', $target->project) }}" wire:navigate class="text-[13px] link link-hover self-start">Open project</a>
                            </div>
                        </div>
                    @endforeach
                    @foreach ($failedTargets as $target)
                        @php $run = $target->latestDeployment; @endphp
                        <div class="flex gap-3 px-4 py-3 border-b border-line" wire:key="attention-run-{{ $target->id }}">
                            <x-icon name="x-circle" class="mt-0.5 shrink-0 text-error" />
                            <div class="flex flex-col gap-0.5 min-w-0">
                                <span class="font-semibold">{{ $run->kind->label() }} {{ $run->number() }} to {{ $target->server->name }} {{ Str::lower($run->status->label()) }}</span>
                                <span class="text-[13px] text-muted">{{ $target->project->name }}: {{ Str::limit((string) $run->outcome, 120) }}</span>
                                <a href="{{ route('deployments.show', $run) }}" wire:navigate class="text-[13px] link link-hover self-start">Open run</a>
                            </div>
                        </div>
                    @endforeach
                    @if ($attention === 0)
                        <p class="px-4 py-4 text-[13px] text-muted inline-flex items-center gap-2"><x-icon name="check-circle" class="text-success" /> Nothing needs attention.</p>
                    @endif
                </section>

                <section class="card bg-base-100 border border-line">
                    <header class="flex items-center min-h-[52px] px-4 border-b border-line">
                        <h2 class="text-[15px] font-semibold">Recent deployments</h2>
                        <a href="{{ route('deployments.index') }}" wire:navigate class="ml-auto text-[13px] link link-hover">View all</a>
                    </header>
                    @forelse ($recent as $run)
                        @php
                            $runAt = $run->created_at->forViewer();
                        @endphp
                        <a href="{{ route('deployments.show', $run) }}" wire:navigate wire:key="recent-{{ $run->id }}"
                           class="flex items-center gap-3 px-4 py-2.5 border-b border-line last:border-b-0 hover:bg-base-200/60">
                            <x-icon :name="$run->status->icon()" @class(['shrink-0', 'animate-spin text-info' => $run->status === DeploymentStatus::Running, 'text-success' => $run->status === DeploymentStatus::Succeeded, 'text-warning' => $run->status === DeploymentStatus::RolledBack, 'text-error' => in_array($run->status, [DeploymentStatus::Failed, DeploymentStatus::Stalled], true), 'text-muted' => in_array($run->status, [DeploymentStatus::Queued, DeploymentStatus::WaitingCi, DeploymentStatus::Cancelled], true)]) />
                            <span class="mono text-[13px]">{{ $run->number() }}</span>
                            <span class="flex flex-col min-w-0">
                                <span class="text-[13px] truncate">{{ $run->project->name }} → {{ $run->server->name }}</span>
                                <span class="text-xs text-muted">{{ $run->status->label() }} · {{ $run->isActive() ? $run->durationLabel() : $runAt->format($runAt->isToday() ? 'H:i' : 'D j M') }}</span>
                            </span>
                        </a>
                    @empty
                        <p class="px-4 py-4 text-[13px] text-muted">No runs yet.</p>
                    @endforelse
                </section>
            </div>
        </div>

        @if ($drift->isNotEmpty())
            <section class="card bg-base-100 border border-line overflow-x-auto">
                <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                    <h2 class="text-[15px] font-semibold">Deployed vs latest</h2>
                    <span class="text-[13px] text-muted">Branch heads from your repositories, or from GitHub every {{ intdiv(\App\Support\Settings::int('ci_poll_interval'), 60) }} min</span>
                </header>
                <table class="table">
                    <thead><tr><th>Project</th><th>Target</th><th>Deployed</th><th>Branch head</th><th>CI</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($drift as $row)
                            @php
                                $target = $row['target'];
                                $project = $target->project;
                                $live = $target->currentRelease;
                                $run = $target->activeDeployment;
                                $last = $target->latestDeployment;
                                $ci = $project->ci_state;
                            @endphp
                            <tr wire:key="drift-{{ $target->id }}">
                                <td><a href="{{ route('projects.show', $project) }}" wire:navigate class="font-semibold hover:underline">{{ $project->name }}</a></td>
                                <td class="text-[13px]">{{ $target->server->name }}</td>
                                <td>
                                    <span class="mono text-[13px]">{{ $live?->shortSha() ?? '—' }}</span>
                                    @if ($last?->status === DeploymentStatus::RolledBack)
                                        <span class="badge badge-sm badge-soft badge-warning">Rolled back</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="mono text-[13px]">{{ $row['head'] ? substr($row['head'], 0, 7) : '—' }}</span>
                                    <span class="block text-xs text-muted">
                                        {{ $project->branch }}{{ ($row['behind'] ?? 0) > 0 ? ' · '.$row['behind'].' '.Str::plural('commit', $row['behind']).' ahead' : '' }}{{ $row['dirty'] ? ' · local · '.$row['dirty'].' uncommitted '.Str::plural('file', $row['dirty']) : '' }}
                                    </span>
                                </td>
                                <td>
                                    @if ($ci)
                                        <span class="badge badge-sm gap-1 {{ $ci->badgeClass() }}"><x-icon :name="$ci->icon()" :size="12" /> {{ $ci->label() }}</span>
                                    @else
                                        <span class="text-muted text-[13px]">—</span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @if ($run)
                                        <a href="{{ route('deployments.show', $run) }}" wire:navigate class="badge badge-sm badge-soft badge-info gap-1">
                                            @if ($run->status === DeploymentStatus::WaitingCi)
                                                <x-icon name="clock" :size="12" /> Waits for CI
                                            @elseif ($run->status === DeploymentStatus::Queued)
                                                <x-icon name="clock" :size="12" /> Queued {{ $run->number() }}
                                            @else
                                                <x-icon name="loader" :size="12" class="animate-spin" /> Deploying{{ $run->commit_sha ? ' '.substr($run->commit_sha, 0, 7) : '' }}
                                            @endif
                                        </a>
                                    @elseif (! $live)
                                        <span class="text-[13px] text-muted">Not deployed</span>
                                    @elseif ($row['head'] !== null && $row['head'] === $live->commit_sha)
                                        <span class="text-[13px] text-muted">Up to date</span>
                                    @elseif (($row['behind'] ?? 0) > 0)
                                        <span class="text-[13px]">{{ $row['behind'] }} behind</span>
                                    @else
                                        <span class="text-[13px] text-muted">{{ $row['head'] ? 'Differs' : 'Unknown' }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif
    @endif
</div>
