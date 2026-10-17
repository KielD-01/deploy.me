<div class="flex flex-col gap-5">
    <x-page-header title="Servers" subtitle="Hosts from ~/.ssh/config, probed on a schedule">
        <x-slot:actions>
            @if ($servers->isNotEmpty())
                <a href="{{ route('servers.create') }}" wire:navigate class="btn btn-primary"><x-icon name="plus" /> Add server</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($servers->isEmpty())
        <x-empty-state icon="server" title="Add your first server">
            Pick a host from your ~/.ssh/config, check the connection and choose where its projects should live.
            <x-slot:actions>
                <a href="{{ route('servers.create') }}" wire:navigate class="btn btn-primary"><x-icon name="plus" /> Add server</a>
            </x-slot:actions>
        </x-empty-state>
    @else
        <section class="card bg-base-100 border border-line overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Server</th>
                        <th>Environment</th>
                        <th>Host</th>
                        <th>CPU</th>
                        <th>Memory</th>
                        <th>Disk</th>
                        <th>Deploy path</th>
                        <th>Last probe</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($servers as $server)
                        @php
                            $snapshot = $server->latestSnapshot;
                            $offline = $server->status === \App\Enums\ServerStatus::Offline;
                            $disk = $snapshot?->diskFor($server->default_deploy_path ?: '/');
                        @endphp
                        <tr wire:key="server-{{ $server->id }}" class="hover:bg-base-200/60">
                            <td>
                                <a href="{{ route('servers.show', $server) }}" wire:navigate class="flex items-center gap-2.5">
                                    <span class="status status-{{ $server->status->tone() }}"></span>
                                    <span class="flex flex-col">
                                        <span class="font-semibold">{{ $server->name }}</span>
                                        <span class="text-xs text-muted">{{ $server->status->label() }} · {{ $server->specs['os'] ?? $server->ssh_alias }}</span>
                                    </span>
                                </a>
                            </td>
                            <td><span class="badge badge-sm {{ $server->env_tag->badgeClass() }}">{{ $server->env_tag->label() }}</span></td>
                            <td class="mono text-muted">{{ $server->connectionLabel() }}</td>
                            <td class="w-36"><x-usage-bar :percent="$offline ? null : $snapshot?->cpu_usage" /></td>
                            <td class="w-36"><x-usage-bar :percent="$offline ? null : $snapshot?->memoryUsedPercent()" /></td>
                            <td class="w-36"><x-usage-bar :percent="$offline ? null : \App\Models\ServerSnapshot::diskUsedPercent($disk)" /></td>
                            <td class="mono">{{ $server->default_deploy_path ?? '—' }}</td>
                            <td class="text-xs text-muted whitespace-nowrap">
                                @if ($offline)
                                    <span class="text-error inline-flex items-center gap-1"><x-icon name="x-circle" :size="12" /> Unreachable</span>
                                @else
                                    {{ $server->last_probe_at?->diffForHumans() ?? 'Not yet' }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    @if ($gitIdentities > 0)
        <p class="text-xs text-muted inline-flex items-center gap-1.5">
            <x-icon name="info" :size="12" />
            {{ $gitIdentities }} {{ Str::plural('host', $gitIdentities) }} in ~/.ssh/config {{ $gitIdentities === 1 ? 'resolves' : 'resolve' }} to a git host. They’re used as git identities and don’t appear here.
        </p>
    @endif
</div>
