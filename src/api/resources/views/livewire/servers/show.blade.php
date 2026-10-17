<div class="flex flex-col gap-5">
    @php
        use App\Enums\ServerStatus;
        $stacks = $latest?->stacks ?? [];
        $caps = $server->capabilities ?? [];
    @endphp

    <div class="flex flex-col gap-2">
        <div class="flex items-center gap-1.5 text-[13px] text-muted">
            <a href="{{ route('servers.index') }}" wire:navigate class="hover:text-base-content">Servers</a>
            <x-icon name="chevron-right" :size="12" /> <span class="mono">{{ $server->ssh_alias }}</span>
        </div>
        <div class="flex items-end gap-4">
            <div class="flex flex-col gap-1 grow min-w-0">
                <div class="flex items-center gap-2.5">
                    <h1 class="text-[22px] leading-7 font-semibold tracking-tight">{{ $server->name }}</h1>
                    <span class="badge {{ $server->env_tag->badgeClass() }}">{{ $server->env_tag->label() }}</span>
                    <x-server-status :status="$server->status" />
                </div>
                <p class="text-muted">
                    <span class="mono">{{ $server->connectionLabel() }}</span>
                    · {{ $server->specs['os'] ?? 'Not probed yet' }}
                    · probed {{ $server->last_probe_at?->diffForHumans() ?? 'never' }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" class="btn btn-sm" wire:click="browse" wire:loading.attr="disabled" wire:target="browse"><x-icon name="folder" :size="14" /> Browse files</button>
                <button type="button" class="btn btn-sm" wire:click="probeNow" wire:loading.attr="disabled" wire:target="probeNow">
                    <x-icon name="refresh" :size="14" wire:loading.class="animate-spin" wire:target="probeNow" /> Probe now
                </button>
            </div>
        </div>
    </div>

    @if ($server->status !== ServerStatus::Online && $server->status_reason)
        <div role="alert" @class(['alert alert-soft text-[13px]', 'alert-error' => $server->status === ServerStatus::Offline, 'alert-warning' => $server->status === ServerStatus::Degraded])>
            <x-icon :name="$server->status === ServerStatus::Offline ? 'x-circle' : 'alert'" /> {{ $server->status_reason }}
        </div>
    @endif

    <div role="tablist" class="tabs tabs-border">
        @foreach (['overview' => 'Overview', 'stacks' => 'Stacks', 'checks' => 'Checks', 'folders' => 'Folders', 'settings' => 'Settings'] as $key => $label)
            <button type="button" role="tab" wire:click="$set('tab', @js($key))" @class(['tab gap-1.5', 'tab-active' => $tab === $key])>
                {{ $label }}
                @if ($key === 'stacks' && count($stacks))<span class="badge badge-xs badge-ghost">{{ count($stacks) }}</span>@endif
                @if ($key === 'checks' && $checks->isNotEmpty())<span class="badge badge-xs badge-ghost">{{ $checks->count() }}</span>@endif
                @if ($key === 'folders' && $folderCount)<span class="badge badge-xs badge-ghost">{{ $folderCount }}</span>@endif
            </button>
        @endforeach
    </div>

    @if ($tab === 'overview')
        <x-spec-cards :specs="$server->specs ?? []" :cpu-usage="$latest?->cpu_usage" :mem-total="$latest?->mem_total" :mem-available="$latest?->mem_available"
                      :disk="$disk" :uptime="$latest?->uptime" :load="$latest ? [$latest->load_1, $latest->load_5, $latest->load_15] : null" />

        <div class="grid grid-cols-2 gap-4">
            @foreach ([['CPU · last 24 h', $cpuHistory], ['Memory · last 24 h', $memHistory]] as [$title, $series])
                <section class="card bg-base-100 border border-line">
                    <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                        <h2 class="text-[15px] font-semibold">{{ $title }}</h2>
                        @if (count(array_filter($series, fn ($v) => $v !== null)))
                            <span class="text-[13px] text-muted">avg {{ round(collect($series)->filter(fn ($v) => $v !== null)->avg()) }}% · peak {{ round(collect($series)->max()) }}%</span>
                        @endif
                    </header>
                    <div class="p-4">
                        <x-sparkline :values="$series" :height="110" />
                        <div class="flex justify-between text-xs text-muted mt-2">
                            <span>{{ $historyFrom?->format('D H:i') ?? '' }}</span><span>Now</span>
                        </div>
                    </div>
                </section>
            @endforeach
        </div>

        {{-- Ten of each from one probe: CPU over its one-second sample, resident memory, and TCP
             traffic (measured as root, so it can be missing, with the reason). --}}
        @php $top = $processes?->processes; @endphp
        <section class="flex flex-col gap-3" aria-label="Busiest processes">
            <div class="flex items-center gap-2.5 min-h-8">
                <h2 class="text-[15px] font-semibold">Busiest processes</h2>
                <span class="text-[13px] text-muted">
                    @if ($processes)
                        from the probe at {{ $processes->captured_at->format('H:i') }}{{ $processes->captured_at->isToday() ? '' : ' on '.$processes->captured_at->format('M j') }}
                    @else
                        the next probe collects them
                    @endif
                </span>
                @if ($processTimes->count() > 1)
                    <select wire:model.live="processesAt" class="select select-sm w-auto ml-auto" aria-label="Which probe to show">
                        <option value="">Latest probe</option>
                        @foreach ($processTimes as $id => $at)
                            <option value="{{ $id }}">{{ $at->format('D H:i') }}</option>
                        @endforeach
                    </select>
                @endif
            </div>

            @if ($top !== null)
                {{-- The three cards are the same height: each list scrolls once it has more rows
                     than about five, and a card with nothing to list keeps the same size. --}}
                <div class="grid grid-cols-3 gap-4">
                    @foreach (['cpu' => ['CPU', '100% is one core', 'Nothing used the CPU during the sample.'], 'mem' => ['Memory', 'resident', 'No processes reported.'], 'net' => ['Network', 'TCP', 'No TCP traffic during the sample.']] as $key => [$title, $unit, $empty])
                        <section class="card bg-base-100 border border-line min-w-0" wire:key="processes-{{ $key }}">
                            <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line shrink-0">
                                <h3 class="text-[15px] font-semibold">{{ $title }}</h3>
                                <span class="text-[13px] text-muted truncate min-w-0">{{ $unit }}</span>
                                {{-- What the processes listed use between them. --}}
                                @if ($key === 'mem' && ($top['mem'] ?? []) !== [])
                                    <span class="ml-auto text-[13px] whitespace-nowrap" title="Resident memory of the processes listed">{{ \App\Support\Format::bytes(array_sum(array_column($top['mem'], 'rss'))) }} total</span>
                                @elseif ($key === 'net' && ($top['net'] ?? []) !== [])
                                    <span class="ml-auto text-[13px] whitespace-nowrap" title="Received (in) and sent (out) each second by the processes listed">↓ in {{ \App\Support\Format::bytes(array_sum(array_column($top['net'], 'rx'))) }}/s · ↑ out {{ \App\Support\Format::bytes(array_sum(array_column($top['net'], 'tx'))) }}/s</span>
                                @endif
                            </header>
                            @if (($top[$key] ?? []) === [])
                                <p class="h-[288px] flex items-center justify-center px-6 text-center text-[13px] text-muted">
                                    {{ $key === 'net' && ($top['net_note'] ?? null) ? 'Not measured: '.$top['net_note'].'.' : $empty }}
                                </p>
                            @else
                                <ul class="h-[288px] overflow-y-auto">
                                    @foreach ($top[$key] as $row)
                                        <li class="flex items-center gap-3 px-4 py-2 border-b border-line last:border-b-0" wire:key="processes-{{ $key }}-{{ $loop->index }}">
                                            <span class="flex flex-col grow min-w-0">
                                                <span class="mono text-[13px] truncate">{{ $row['name'] }}</span>
                                                <span class="text-xs text-muted truncate">{{ $row['container'] ? $row['container'].' · ' : '' }}pid {{ $row['pid'] }}</span>
                                            </span>
                                            @if ($key === 'cpu')
                                                <span class="mono text-[13px] shrink-0">{{ number_format($row['cpu'], 1) }}%</span>
                                            @elseif ($key === 'mem')
                                                <span class="mono text-[13px] shrink-0">{{ \App\Support\Format::bytes($row['rss']) }}</span>
                                            @else
                                                <span class="flex flex-col items-end shrink-0">
                                                    <span class="mono text-[13px]">{{ \App\Support\Format::bytes($row['tx'] + $row['rx']) }}/s</span>
                                                    <span class="text-xs text-muted">↓ {{ \App\Support\Format::bytes($row['rx']) }} · ↑ {{ \App\Support\Format::bytes($row['tx']) }}</span>
                                                </span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </section>
                    @endforeach
                </div>
            @endif
        </section>

        <div class="grid grid-cols-3 gap-4 items-start">
            @if ($targets->isEmpty())
                <x-empty-state icon="repo" title="No projects on this server yet" class="col-span-2">
                    Assign a project to {{ $server->name }} from the project’s Targets tab. It shows up here with its path, last run and health.
                </x-empty-state>
            @else
                <section class="card bg-base-100 border border-line col-span-2">
                    <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                        <h2 class="text-[15px] font-semibold">Targets</h2>
                        <span class="text-[13px] text-muted">{{ $targets->count() }} {{ Str::plural('project', $targets->count()) }}</span>
                    </header>
                    <table class="table">
                        <thead><tr><th>Project</th><th>Path</th><th>Last run</th><th>Health</th></tr></thead>
                        <tbody>
                            @foreach ($targets as $target)
                                @php $run = $target->latestDeployment; @endphp
                                <tr wire:key="target-{{ $target->id }}">
                                    <td><a href="{{ route('projects.show', $target->project) }}" wire:navigate class="font-semibold hover:underline">{{ $target->project->name }}</a></td>
                                    <td class="mono">{{ $target->path }}</td>
                                    <td>
                                        @if ($run)
                                            <a href="{{ route('deployments.show', $run) }}" wire:navigate class="badge badge-sm gap-1 {{ $run->status->badgeClass() }}">
                                                {{ $run->kind->label() }} {{ $run->number() }} · {{ Str::lower($run->status->label()) }}
                                            </a>
                                        @else
                                            <span class="text-[13px] text-muted">Never</span>
                                        @endif
                                    </td>
                                    <td><span class="badge badge-sm {{ $target->health->badgeClass() }}">{{ $target->health->label() }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>
            @endif

            <section class="card bg-base-100 border border-line">
                <header class="flex items-center min-h-[52px] px-4 border-b border-line"><h2 class="text-[15px] font-semibold">Access</h2></header>
                <dl class="p-4 grid grid-cols-[100px_minmax(0,1fr)] gap-y-2.5 gap-x-4 text-[13px]">
                    <dt class="text-muted">Host key</dt>
                    <dd class="mono truncate">{{ $hostKey ? $hostKey->key_type.' '.$hostKey->fingerprint : 'From your ~/.ssh/known_hosts' }}</dd>
                    <dt class="text-muted">Trusted</dt>
                    <dd>{{ $hostKey?->trusted_at?->toFormattedDateString() ?? 'Before deploy.me' }}</dd>
                    <dt class="text-muted">Key</dt>
                    <dd class="mono truncate">{{ $server->identity_files[0] ?? 'default keys' }}</dd>
                    <dt class="text-muted">sudo</dt>
                    <dd>
                        @if ($caps['sudo_nopasswd'] ?? false)
                            <span class="badge badge-sm badge-soft badge-success">Passwordless</span>
                        @else
                            <span class="badge badge-sm badge-soft badge-warning gap-1"><x-icon name="alert" :size="12" /> Needs a password</span>
                        @endif
                    </dd>
                    <dt class="text-muted">Latency</dt>
                    <dd>{{ $latest?->ssh_latency_ms !== null ? $latest->ssh_latency_ms.' ms' : '—' }}</dd>
                </dl>
            </section>
        </div>

        <section class="card bg-base-100 border border-line">
            <header class="flex items-center min-h-[52px] px-4 border-b border-line"><h2 class="text-[15px] font-semibold">Capabilities</h2></header>
            <div class="p-4"><x-capability-chips :capabilities="$caps" /></div>
        </section>

        <x-audit-trail :entries="$trail" :link="route('audit', ['server' => $server->id])" />
    @endif

    @if ($tab === 'stacks')
        <section class="card bg-base-100 border border-line">
            <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                <h2 class="text-[15px] font-semibold">Stacks on this server</h2><span class="text-[13px] text-muted mono">docker compose ls</span>
            </header>
            @if ($problem)
                <div role="alert" class="alert alert-soft alert-error text-[13px] m-4 w-auto"><x-icon name="alert" /> {{ $problem }}</div>
            @endif
            @if ($stacks === [])
                <p class="px-4 py-6 text-[13px] text-muted">No Compose stacks found at the last probe.</p>
            @else
                <table class="table">
                    <thead><tr><th>Stack</th><th>Compose files</th><th>Status</th><th>Managed by</th></tr></thead>
                    <tbody>
                        @foreach ($stacks as $stack)
                            @php $file = explode(',', $stack['ConfigFiles'] ?? '')[0]; @endphp
                            <tr wire:key="stack-{{ $stack['Name'] }}">
                                <td class="font-semibold">{{ $stack['Name'] }}</td>
                                <td class="mono text-muted">{{ $stack['ConfigFiles'] ?? '—' }}</td>
                                <td><span @class(['badge badge-sm badge-soft', 'badge-success' => str_starts_with($stack['Status'] ?? '', 'running'), 'badge-warning' => ! str_starts_with($stack['Status'] ?? '', 'running')])>{{ $stack['Status'] ?? 'unknown' }}</span></td>
                                @php
                                    $managed = $file ? $targets->first(fn ($target) => str_starts_with($file, rtrim($target->path, '/').'/')) : null;
                                    $adopt = $adoptable[$stack['Name']] ?? null;
                                @endphp
                                <td>
                                    <div class="flex items-center gap-2">
                                        @if ($file && $server->isReserved(dirname($file)))
                                            <span class="badge badge-sm badge-soft badge-warning gap-1"><x-icon name="lock" :size="12" /> Reserved · shared edge and data</span>
                                        @elseif ($managed)
                                            <a href="{{ route('projects.show', $managed->project) }}" wire:navigate class="link text-[13px]">{{ $managed->project->name }}</a>
                                        @else
                                            <span class="text-xs text-muted">Not managed by deploy.me</span>
                                        @endif
                                        @if ($adopt)
                                            <span class="tooltip tooltip-left" data-tip="Record what runs here as {{ $adopt['project']->name }}’s live release, in {{ $adopt['folder'] }}. Nothing on the server changes.">
                                                <button type="button" class="btn btn-xs" wire:click="adoptStack(@js($stack['Name']))" wire:loading.attr="disabled" wire:target="adoptStack">
                                                    <x-icon name="plus" :size="12" /> Adopt{{ $managed ? '' : ' into '.$adopt['project']->name }}
                                                </button>
                                            </span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    @endif

    @if ($tab === 'checks')
        <section class="card bg-base-100 border border-line">
            <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                <h2 class="text-[15px] font-semibold">Recent health checks</h2>
                <span class="text-[13px] text-muted">Each target's app services and health URL, asked from this server · kept {{ $checkDays }} days</span>
            </header>
            @if ($checks->isEmpty())
                <p class="px-4 py-6 text-[13px] text-muted">
                    No health checks yet. They start once a project deployed here has a health URL or a Docker stack deploy.me runs.
                </p>
            @else
                <table class="table">
                    <thead><tr><th>When</th><th>Project</th><th>Result</th><th>What it found</th><th>Took</th></tr></thead>
                    <tbody>
                        @foreach ($checks as $check)
                            <tr wire:key="check-{{ $check->id }}">
                                <td class="text-[13px] whitespace-nowrap" title="{{ $check->checked_at->toDayDateTimeString() }}">{{ $check->checked_at->diffForHumans() }}</td>
                                <td class="text-[13px]">
                                    <a href="{{ route('projects.show', $check->target->project) }}" wire:navigate class="link">{{ $check->target->project->name }}</a>
                                </td>
                                <td><span class="badge badge-sm {{ $check->health->badgeClass() }}">{{ $check->health->label() }}</span></td>
                                <td class="text-[13px] text-muted">{{ $check->detail }}</td>
                                <td class="text-xs text-muted whitespace-nowrap">{{ $check->duration_ms !== null ? $check->duration_ms.' ms' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    @endif

    @if ($tab === 'folders')
        <livewire:servers.folders :server-id="$server->id" :key="'folders-'.$server->id" />
    @endif

    @if ($tab === 'settings')
        <form wire:submit="saveSettings" class="card bg-base-100 border border-line max-w-3xl">
            <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                <h2 class="text-[15px] font-semibold">Server settings</h2>
                @if ($notice)<span class="text-[13px] text-success inline-flex items-center gap-1"><x-icon name="check" :size="12" /> {{ $notice }}</span>@endif
            </header>
            <div class="p-4 grid grid-cols-2 gap-4">
                <label class="flex flex-col gap-1.5">
                    <span class="text-[13px] font-medium">Name</span>
                    <input type="text" wire:model="name" class="input w-full" maxlength="100">
                    @error('name')<span class="text-error text-xs">{{ $message }}</span>@enderror
                </label>
                <div class="flex flex-col gap-1.5">
                    <span class="text-[13px] font-medium">Environment</span>
                    <div class="join">
                        @foreach ($envTags as $tag)
                            <button type="button" wire:click="$set('envTag', @js($tag->value))" @class(['btn btn-sm join-item', 'btn-active' => $envTag === $tag->value])>{{ $tag->label() }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="flex flex-col gap-1.5">
                    <span class="text-[13px] font-medium">Default deploy path</span>
                    <div class="flex gap-2">
                        <input type="text" wire:model="defaultPath" class="input grow font-mono">
                        <button type="button" class="btn btn-square" wire:click="choosePath" wire:loading.attr="disabled" wire:target="choosePath" aria-label="Browse folders"><x-icon name="folder" /></button>
                    </div>
                    @error('defaultPath')<span class="text-error text-xs">{{ $message }}</span>@enderror
                </div>
                <label class="flex flex-col gap-1.5">
                    <span class="text-[13px] font-medium">Probe</span>
                    <select wire:model="probeInterval" class="select w-full">
                        @foreach ($intervals as $seconds => $label)
                            <option value="{{ $seconds }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <div class="flex items-center gap-2 px-4 py-3 border-t border-line">
                <button type="button" class="btn btn-sm btn-ghost text-error" wire:click="archive"
                        wire:confirm="Remove {{ $server->name }} from deploy.me? Nothing on the server changes.">
                    <x-icon name="trash" :size="14" /> Remove server
                </button>
                <span class="grow"></span>
                <button type="submit" class="btn btn-primary"><x-icon name="check" /> Save</button>
            </div>
        </form>
    @endif

    @foreach ([['browsing', 'browsePath', 'Browse files'], ['choosingPath', 'defaultPath', 'Choose the default deploy path']] as [$flag, $model, $heading])
        @if ($$flag)
            <div class="modal modal-open" role="dialog" aria-label="{{ $heading }}">
                <div class="modal-box max-w-3xl p-0">
                    <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                        <h2 class="text-[15px] font-semibold">{{ $heading }}</h2>
                        <span class="text-[13px] text-muted mono">{{ $server->ssh_alias }}</span>
                        <button type="button" class="btn btn-sm btn-ghost btn-square ml-auto" wire:click="$set(@js($flag), false)" aria-label="Close"><x-icon name="x" /></button>
                    </header>
                    <livewire:remote.folder-browser :alias="$server->ssh_alias" wire:model="{{ $model }}" :key="$flag.'-'.$server->id" />
                    @if ($flag === 'choosingPath')
                        <div class="flex justify-end gap-2 px-4 py-3 border-t border-line">
                            <button type="button" class="btn btn-primary btn-sm" wire:click="usePath">Use {{ $defaultPath }}</button>
                        </div>
                    @endif
                </div>
                <div class="modal-backdrop" wire:click="$set(@js($flag), false)"></div>
            </div>
        @endif
    @endforeach
</div>
