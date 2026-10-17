{{-- The Console theme's server page: htop. Meters, the last day's CPU and memory, the
     busiest processes, `docker compose ls`, the targets and their health checks. --}}
<div class="con-page" x-data="pageKeys" x-on:keydown.window="key($event)">
    @php
        use App\Enums\ServerStatus;
        use App\Enums\TargetHealth;
        use App\Support\Format;
        use Illuminate\Support\Str;

        $stacks = $latest?->stacks ?? [];
        $caps = $server->capabilities ?? [];
        $specs = $server->specs ?? [];
        $statusTone = match ($server->status) {
            ServerStatus::Online => 't-ok',
            ServerStatus::Degraded => 't-warn',
            ServerStatus::Offline => 't-err',
            default => 't-faint',
        };
        $healthTone = fn (TargetHealth $health) => match ($health) {
            TargetHealth::Healthy => 't-ok',
            TargetHealth::Unhealthy => 't-err',
            default => 't-faint',
        };
        $meter = fn (?float $percent, int $width = 24) => \App\Livewire\Console\Htop::meter($percent, $width);
        // At most 48 bars for the day, each the average of the probes it covers.
        $bars = function (array $series): array {
            $values = array_values(array_filter($series, fn ($value) => $value !== null));

            return $values === [] ? [] : collect($values)->chunk((int) max(1, ceil(count($values) / 48)))->map(fn ($chunk) => (float) $chunk->avg())->values()->all();
        };
        $cpuBars = $bars($cpuHistory);
        $memBars = $bars($memHistory);
        $top = $processes?->processes;
        // One row per process, whichever list it's in, so each column fills where it can.
        $byPid = collect(['cpu', 'mem', 'net'])->flatMap(fn ($key) => $top[$key] ?? [])->groupBy('pid')->map(fn ($rows) => $rows->reduce(fn ($row, $next) => [...$row, ...$next], []));
        $swapUsed = ($latest?->swap_total ?? 0) > 0 ? 100 * (1 - $latest->swap_free / $latest->swap_total) : null;
        $disks = collect($latest?->disks ?? [])->sortBy('mount')->take(4);
        $checksByTarget = $checks->groupBy('target_id');
        $tabs = ['overview' => ['h', 'htop'], 'stacks' => ['o', 'stacks'], 'checks' => ['c', 'checks'], 'folders' => ['f', 'folders'], 'settings' => ['e', 'edit']];
        $chips = [
            [$caps['docker'] ?? null, 'docker '.($caps['docker'] ?? ''), 'no docker'],
            [$caps['compose'] ?? null, 'compose v'.($caps['compose'] ?? ''), 'no compose'],
            [$caps['git'] ?? null, 'git '.($caps['git'] ?? ''), 'no git'],
            [$caps['rsync'] ?? null, 'rsync '.($caps['rsync'] ?? ''), 'no rsync'],
            [($caps['gnu_mv'] ?? false) && ($caps['flock'] ?? false), 'mv -T · flock', 'no GNU mv -T and flock'],
            [($caps['caddy_docker_proxy'] ?? null) && ($caps['caddy_on_network'] ?? false), 'caddy-docker-proxy', 'no edge proxy'],
            [$caps['docker_group'] ?? false, 'docker group', 'not in docker group'],
            [$caps['sudo_nopasswd'] ?? false, 'sudo -n', 'sudo needs a password'],
        ];
    @endphp

    <header class="con-prompt">
        <span class="min-w-0 truncate">
            <span class="t-ok">deploy{{ '@'.$server->slug }}</span><span class="t-mute">:</span><span class="t-run">~/servers/{{ $server->slug }}</span>
            <span class="t-mute">$</span> watch probe<span class="t-cursor"></span>
        </span>
        <nav class="con-keys" aria-label="Keys">
            <button type="button" class="con-key" data-key="p" wire:click="probeNow" wire:loading.attr="disabled" wire:target="probeNow">
                <kbd>p</kbd> <span wire:loading.remove wire:target="probeNow">Probe now</span><span wire:loading wire:target="probeNow" class="t-run">probing…</span>
            </button>
            <button type="button" class="con-key" data-key="b" wire:click="browse" wire:loading.attr="disabled" wire:target="browse"><kbd>b</kbd> browse files</button>
        </nav>
    </header>

    <div role="tablist" class="con-tabs" aria-label="Views">
        @foreach ($tabs as $key => [$letter, $label])
            <button type="button" role="tab" data-key="{{ $letter }}" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', @js($key))">
                {{ $loop->index }}:{{ $label }}{{ $tab === $key ? '*' : '' }}
                @if ($key === 'stacks' && count($stacks))<span class="t-faint">({{ count($stacks) }})</span>@endif
                @if ($key === 'checks' && $checks->isNotEmpty())<span class="t-faint">({{ $checks->count() }})</span>@endif
                @if ($key === 'folders' && $folderCount)<span class="t-faint">({{ $folderCount }})</span>@endif
                <span class="t-faint">· {{ $letter }}</span>
            </button>
        @endforeach
    </div>

    <div class="con-line pt-2">
        <h1 class="t-b text-[15px]">{{ $server->name }}</h1>
        <span class="t-v">{{ Str::lower($server->env_tag->label()) }}</span>
        <span class="{{ $statusTone }}">● {{ $server->status?->value ?? 'not probed' }}</span>
        <span class="t-mute">{{ $server->connectionLabel() }} · {{ $specs['os'] ?? 'not probed yet' }} · probed {{ $server->last_probe_at?->diffForHumans() ?? 'never' }}</span>
    </div>
    @if ($server->status !== ServerStatus::Online && $server->status_reason)
        <div role="alert" class="con-line {{ $server->status === ServerStatus::Offline ? 't-err' : 't-warn' }}">{{ $server->status === ServerStatus::Offline ? '✕' : '!' }} {{ $server->status_reason }}</div>
    @endif
    @if ($problem)
        <div role="alert" class="con-line t-err">✕ {{ $problem }}</div>
    @endif

    @if ($tab === 'overview' || $tab === 'stacks')
        <div class="con-grid">
            @if ($tab === 'overview')
                <section class="con-box" aria-label="System">
                    <span class="con-box-t"><b>{{ $server->slug }}</b> · up {{ $latest ? Format::uptime($latest->uptime) : '—' }} · load {{ $latest ? $latest->load_1.' '.$latest->load_5.' '.$latest->load_15 : '—' }}</span>
                    @if ($latest === null)
                        <p class="t-mute">Not probed yet · <button type="button" class="t-run hover:underline" wire:click="probeNow">probe now</button></p>
                    @else
                        <div class="flex flex-col gap-0.5">
                            @php $cpu = $meter($latest->cpu_usage); $mem = $meter($latest->memoryUsedPercent()); @endphp
                            <div class="con-meter"><span>cpu</span><span><span class="t-faint">[</span><span class="t-a">{{ $cpu['fill'] }}</span>{{ $cpu['rest'] }}<span class="t-faint">]</span></span><span class="t-b font-normal">{{ round((float) $latest->cpu_usage) }}%</span></div>
                            <div class="con-meter"><span>mem</span><span><span class="t-faint">[</span><span class="t-v">{{ $mem['fill'] }}</span>{{ $mem['rest'] }}<span class="t-faint">]</span></span><span>{{ Format::bytes(($latest->mem_total ?? 0) - ($latest->mem_available ?? 0)) }} / {{ Format::bytes($latest->mem_total) }}</span></div>
                            @if ($swapUsed !== null)
                                @php $swap = $meter($swapUsed); @endphp
                                <div class="con-meter"><span>swap</span><span><span class="t-faint">[</span><span class="t-v">{{ $swap['fill'] }}</span>{{ $swap['rest'] }}<span class="t-faint">]</span></span><span>{{ Format::bytes($latest->swap_total - $latest->swap_free) }} / {{ Format::bytes($latest->swap_total) }}</span></div>
                            @endif
                            @foreach ($disks as $each)
                                @php $used = $meter(\App\Models\ServerSnapshot::diskUsedPercent($each)); $deploys = ($disk['mount'] ?? null) === $each['mount']; @endphp
                                <div class="con-meter" title="{{ $each['source'] }} · {{ $each['fstype'] }}{{ $deploys ? ' · where deploys go' : '' }}"><span>{{ $each['mount'] }}</span><span><span class="t-faint">[</span><span class="{{ $deploys ? 't-warn' : 't-run' }}">{{ $used['fill'] }}</span>{{ $used['rest'] }}<span class="t-faint">]</span></span><span>{{ Format::bytes($each['available']) }} free</span></div>
                            @endforeach
                        </div>
                    @endif
                    <p class="mt-2 t-mute">
                        {{ collect([
                            ($specs['cores'] ?? null) ? $specs['cores'].' '.Str::plural('core', $specs['cores']).(($specs['threads'] ?? null) && $specs['threads'] !== $specs['cores'] ? ' · '.$specs['threads'].' threads' : '') : null,
                            $specs['cpu_model'] ?? null,
                            $specs['kernel'] ?? null,
                            $latest?->ssh_latency_ms !== null ? 'ssh '.$latest->ssh_latency_ms.' ms' : null,
                        ])->filter()->implode(' · ') }}
                    </p>
                </section>

                <section class="con-box" aria-label="Last 24 hours">
                    <span class="con-box-t"><b>cpu</b> · mem · 24 h{{ $cpuBars !== [] ? ' · peak '.round(max($cpuBars)).'%' : '' }}</span>
                    @if ($cpuBars === [])
                        <p class="t-mute">No probes in the last day yet.</p>
                    @else
                        <div class="con-spark h-[64px]" role="img" aria-label="CPU, last 24 hours, average {{ round(collect($cpuBars)->avg()) }}%">
                            @foreach ($cpuBars as $value)<span class="bg-accent opacity-85" style="height: {{ max(1, round($value)) }}%"></span>@endforeach
                        </div>
                        <div class="con-spark h-[34px] mt-1.5" role="img" aria-label="Memory, last 24 hours, average {{ round(collect($memBars)->avg()) }}%">
                            @foreach ($memBars as $value)<span class="bg-secondary opacity-70" style="height: {{ max(1, round($value)) }}%"></span>@endforeach
                        </div>
                        <div class="flex justify-between t-faint mt-1"><span>{{ $historyFrom?->format('D H:i') ?? '' }}</span><span>cpu avg {{ round(collect($cpuBars)->avg()) }}% · mem avg {{ round(collect($memBars)->avg()) }}%</span><span>now</span></div>
                    @endif
                </section>

                {{-- Ten of each from one probe: CPU over its one-second sample, resident memory, and
                     TCP traffic (measured as root, so it can be missing, with the reason). --}}
                <section class="con-box con-wide" aria-label="Busiest processes" x-data="{ by: 'cpu' }">
                    <span class="con-box-t">
                        <b>processes</b>
                        <span>· {{ $processes ? 'probe at '.$processes->captured_at->format('H:i').($processes->captured_at->isToday() ? '' : ' on '.$processes->captured_at->format('M j')) : 'the next probe collects them' }} ·</span>
                        @foreach (['cpu', 'mem', 'net'] as $key)
                            <button type="button" x-on:click="by = @js($key)" x-bind:class="{ 'is-on': by === @js($key) }">{{ $key }}</button>
                        @endforeach
                    </span>
                    @if ($processTimes->count() > 1)
                        <select wire:model.live="processesAt" class="select select-xs w-auto absolute right-3 -top-3 bg-base-200" aria-label="Which probe to show">
                            <option value="">latest probe</option>
                            @foreach ($processTimes as $id => $at)
                                <option value="{{ $id }}">{{ $at->format('D H:i') }}</option>
                            @endforeach
                        </select>
                    @endif
                    @if ($top === null)
                        <p class="t-mute">No process list yet.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="con-table">
                                <thead><tr><th class="text-right">cpu%</th><th></th><th class="text-right">mem</th><th class="text-right">net ↓↑</th><th>process</th><th class="w-full">container</th><th class="text-right">pid</th></tr></thead>
                                @foreach (['cpu' => 'Nothing used the CPU during the sample.', 'mem' => 'No processes reported.', 'net' => 'No TCP traffic during the sample.'] as $key => $empty)
                                    <tbody x-show="by === @js($key)" @if ($key !== 'cpu') x-cloak @endif wire:key="processes-{{ $key }}">
                                        @forelse ($top[$key] ?? [] as $row)
                                            @php
                                                $all = $byPid[$row['pid']] ?? $row;
                                                $pct = (float) ($all['cpu'] ?? 0);
                                                $hot = $pct >= 50;
                                            @endphp
                                            <tr wire:key="processes-{{ $key }}-{{ $loop->index }}">
                                                <td class="text-right tabular-nums {{ $hot ? 't-warn' : '' }}">{{ isset($all['cpu']) ? number_format($all['cpu'], 1) : '—' }}</td>
                                                <td class="whitespace-pre {{ $hot ? 't-warn' : 't-a' }}">{{ str_pad(isset($all['cpu']) ? str_repeat('█', (int) max(1, min(10, round($pct / 10)))) : '', 10) }}</td>
                                                <td class="text-right tabular-nums">{{ isset($all['rss']) ? Format::bytes($all['rss']) : '—' }}</td>
                                                <td class="text-right tabular-nums t-mute whitespace-nowrap">{{ isset($all['rx']) ? Format::bytes($all['rx']).'/s '.Format::bytes($all['tx']).'/s' : '—' }}</td>
                                                <td class="t-b font-semibold">{{ $row['name'] }}</td>
                                                <td class="t-mute">{{ $row['container'] ?: '—' }}</td>
                                                <td class="text-right t-faint tabular-nums">{{ $row['pid'] }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="7" class="t-mute">{{ $key === 'net' && ($top['net_note'] ?? null) ? 'Not measured: '.$top['net_note'].'.' : $empty }}</td></tr>
                                        @endforelse
                                    </tbody>
                                @endforeach
                            </table>
                        </div>
                    @endif
                </section>
            @endif

            <section @class(['con-box', 'con-wide' => $tab === 'stacks']) aria-label="Compose stacks">
                <span class="con-box-t"><b>docker compose ls</b>@if ($tab === 'overview')<span>·</span><button type="button" wire:click="$set('tab', 'stacks')">files · o</button>@endif</span>
                @if ($stacks === [])
                    <p class="t-mute">No Compose stacks found at the last probe.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="con-table">
                            <thead><tr><th>NAME</th><th>STATUS</th>@if ($tab === 'stacks')<th>CONFIG FILES</th>@endif<th>PROJECT</th></tr></thead>
                            <tbody>
                                @foreach ($stacks as $stack)
                                    @php
                                        $file = explode(',', $stack['ConfigFiles'] ?? '')[0];
                                        $managed = $file ? $targets->first(fn ($target) => str_starts_with($file, rtrim($target->path, '/').'/')) : null;
                                        $adopt = $adoptable[$stack['Name']] ?? null;
                                        $running = str_starts_with($stack['Status'] ?? '', 'running');
                                    @endphp
                                    <tr wire:key="stack-{{ $stack['Name'] }}">
                                        <td>{{ $stack['Name'] }}</td>
                                        <td class="whitespace-nowrap {{ $running ? ($managed ? 't-run' : 't-ok') : 't-faint' }}">{{ $stack['Status'] ?? 'unknown' }}</td>
                                        @if ($tab === 'stacks')<td class="t-mute break-all">{{ $stack['ConfigFiles'] ?? '—' }}</td>@endif
                                        <td>
                                            <span class="inline-flex flex-wrap items-center gap-2">
                                                @if ($file && $server->isReserved(dirname($file)))
                                                    <span class="t-warn">Reserved · shared edge and data</span>
                                                @elseif ($managed)
                                                    <a href="{{ route('projects.show', $managed->project) }}" wire:navigate class="hover:underline">{{ $managed->project->name }}</a>
                                                @else
                                                    <span class="t-faint">not managed by deploy.me</span>
                                                @endif
                                                @if ($adopt)
                                                    <button type="button" class="con-act" wire:click="adoptStack(@js($stack['Name']))" wire:loading.attr="disabled" wire:target="adoptStack"
                                                            title="Record what runs here as {{ $adopt['project']->name }}’s live release, in {{ $adopt['folder'] }}. Nothing on the server changes.">
                                                        + Adopt{{ $managed ? '' : ' into '.$adopt['project']->name }}
                                                    </button>
                                                @endif
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            @if ($tab === 'overview')
                <section class="con-box" aria-label="Health checks">
                    <span class="con-box-t"><b>checks</b> · last {{ $checks->count() }}<span>·</span><button type="button" wire:click="$set('tab', 'checks')">all · c</button></span>
                    @if ($checksByTarget->isEmpty())
                        <p class="t-mute">No health checks yet. They start once a project deployed here has a health URL or a Docker stack deploy.me runs.</p>
                    @else
                        <div class="flex flex-col gap-1">
                            @foreach ($checksByTarget as $targetChecks)
                                @php $last = $targetChecks->first(); @endphp
                                <div class="flex items-center gap-2 min-w-0" wire:key="spark-{{ $last->target_id }}">
                                    <a href="{{ route('projects.show', $last->target->project) }}" wire:navigate class="w-[12ch] shrink-0 truncate hover:underline">{{ $last->target->project->name }}</a>
                                    <span class="tracking-[1px] whitespace-pre overflow-hidden" aria-label="{{ $targetChecks->where('health', TargetHealth::Healthy)->count() }} of {{ $targetChecks->count() }} healthy">@foreach ($targetChecks->reverse() as $check)<span class="{{ $healthTone($check->health) }}" title="{{ $check->checked_at->toDayDateTimeString() }} · {{ $check->detail }}">│</span>@endforeach</span>
                                    <span class="ml-auto shrink-0 {{ $healthTone($last->health) }}" title="{{ $last->detail }}">{{ Str::limit((string) $last->detail, 22) ?: Str::lower($last->health->label()) }}{{ $last->duration_ms !== null ? ' '.$last->duration_ms.'ms' : '' }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>

                <section class="con-box" aria-label="Targets">
                    <span class="con-box-t"><b>targets</b> · {{ $targets->count() }} {{ Str::plural('project', $targets->count()) }}</span>
                    @if ($targets->isEmpty())
                        <p class="t-mute">No projects on this server yet. Assign one from the project’s Targets tab: it shows up here with its path, last run and health.</p>
                    @else
                        <div class="flex flex-col">
                            @foreach ($targets as $target)
                                @php $run = $target->latestDeployment; @endphp
                                <div class="flex flex-col py-1 border-b border-dashed border-line last:border-b-0" wire:key="target-{{ $target->id }}">
                                    <span class="flex gap-2">
                                        <span class="{{ $healthTone($target->health) }}">●</span>
                                        <a href="{{ route('projects.show', $target->project) }}" wire:navigate class="t-b font-semibold hover:underline">{{ $target->project->name }}</a>
                                        <span class="ml-auto {{ $healthTone($target->health) }}">{{ Str::lower($target->health->label()) }}</span>
                                    </span>
                                    <span class="t-mute">
                                        {{ $target->path }} ·
                                        @if ($run)
                                            <a href="{{ route('deployments.show', $run) }}" wire:navigate class="t-run hover:underline">{{ $run->kind->label() }} {{ $run->number() }} · {{ Str::lower($run->status->label()) }}</a>
                                        @else
                                            never deployed
                                        @endif
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>

                <section class="con-box" aria-label="Access">
                    <span class="con-box-t"><b>ssh</b> · {{ $server->ssh_alias }}</span>
                    <dl class="grid grid-cols-[9ch_minmax(0,1fr)] gap-x-3">
                        <dt class="t-mute">host key</dt><dd class="truncate">{{ $hostKey ? $hostKey->key_type.' '.$hostKey->fingerprint : 'from your ~/.ssh/known_hosts' }}</dd>
                        <dt class="t-mute">trusted</dt><dd>{{ $hostKey?->trusted_at?->toFormattedDateString() ?? 'before deploy.me' }}</dd>
                        <dt class="t-mute">key</dt><dd class="truncate">{{ $server->identity_files[0] ?? 'default keys' }}</dd>
                        <dt class="t-mute">sudo</dt><dd class="{{ ($caps['sudo_nopasswd'] ?? false) ? 't-ok' : 't-warn' }}">{{ ($caps['sudo_nopasswd'] ?? false) ? 'passwordless' : 'needs a password' }}</dd>
                        <dt class="t-mute">latency</dt><dd>{{ $latest?->ssh_latency_ms !== null ? $latest->ssh_latency_ms.' ms' : '—' }}</dd>
                    </dl>
                    <p class="mt-2 flex flex-wrap gap-x-3" aria-label="Capabilities">
                        @foreach ($chips as [$ok, $yes, $no])
                            <span class="{{ $ok ? 't-ok' : 't-warn' }}">{{ $ok ? '✓ '.$yes : '✕ '.$no }}</span>
                        @endforeach
                    </p>
                </section>

                @include('partials.trail', ['entries' => $trail, 'link' => route('audit', ['server' => $server->id])])
            @endif
        </div>
    @endif

    @if ($tab === 'checks')
        <div class="con-grid">
            <section class="con-box con-wide" aria-label="Recent health checks">
                <span class="con-box-t"><b>checks</b> · each target’s app services and health URL, asked from this server · kept {{ $checkDays }} days</span>
                @if ($checks->isEmpty())
                    <p class="t-mute">No health checks yet. They start once a project deployed here has a health URL or a Docker stack deploy.me runs.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="con-table">
                            <thead><tr><th>WHEN</th><th>PROJECT</th><th>RESULT</th><th>WHAT IT FOUND</th><th class="text-right">TOOK</th></tr></thead>
                            <tbody>
                                @foreach ($checks as $check)
                                    <tr wire:key="check-{{ $check->id }}">
                                        <td class="whitespace-nowrap t-mute" title="{{ $check->checked_at->toDayDateTimeString() }}">{{ $check->checked_at->diffForHumans(short: true) }}</td>
                                        <td><a href="{{ route('projects.show', $check->target->project) }}" wire:navigate class="hover:underline">{{ $check->target->project->name }}</a></td>
                                        <td class="{{ $healthTone($check->health) }}">{{ Str::lower($check->health->label()) }}</td>
                                        <td class="t-mute">{{ $check->detail }}</td>
                                        <td class="text-right t-mute whitespace-nowrap">{{ $check->duration_ms !== null ? $check->duration_ms.' ms' : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>
    @endif

    @if ($tab === 'folders')
        <div class="p-[14px]">
            <livewire:servers.folders :server-id="$server->id" :key="'folders-'.$server->id" />
        </div>
    @endif

    @if ($tab === 'settings')
        <div class="con-grid">
            <form wire:submit="saveSettings" class="con-box con-wide con-conf max-w-3xl" aria-label="Server settings">
                <span class="con-box-t"><b>server.conf</b> · {{ $server->ssh_alias }}@if ($notice)<span class="t-ok">· ✓ {{ $notice }}</span>@endif</span>
                <div class="grid grid-cols-[14ch_minmax(0,1fr)] items-center gap-x-3 gap-y-2">
                    <label for="server-name" class="t-v">name</label>
                    <div>
                        <input id="server-name" type="text" wire:model="name" class="input input-sm w-full max-w-md" maxlength="100">
                        @error('name')<span class="t-err block">{{ $message }}</span>@enderror
                    </div>
                    <span class="t-v">environment</span>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($envTags as $tag)
                            <button type="button" wire:click="$set('envTag', @js($tag->value))" @class(['con-act', 'border-primary text-primary' => $envTag === $tag->value])>{{ Str::lower($tag->label()) }}</button>
                        @endforeach
                    </div>
                    <label for="server-path" class="t-v">deploy_path</label>
                    <div>
                        <div class="flex gap-2 max-w-md">
                            <input id="server-path" type="text" wire:model="defaultPath" class="input input-sm grow">
                            <button type="button" class="con-act" wire:click="choosePath" wire:loading.attr="disabled" wire:target="choosePath" aria-label="Browse folders">browse</button>
                        </div>
                        @error('defaultPath')<span class="t-err block">{{ $message }}</span>@enderror
                    </div>
                    <label for="server-probe" class="t-v">probe</label>
                    <select id="server-probe" wire:model="probeInterval" class="select select-sm w-full max-w-md">
                        @foreach ($intervals as $seconds => $label)
                            <option value="{{ $seconds }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-center gap-2 mt-4 pt-3 border-t border-line">
                    <button type="button" class="con-act is-danger" wire:click="archive" wire:confirm="Remove {{ $server->name }} from deploy.me? Nothing on the server changes.">remove server</button>
                    <span class="grow"></span>
                    <button type="submit" class="btn btn-sm btn-primary">Save</button>
                </div>
            </form>
        </div>
    @endif

    @include('livewire.servers.partials.folder-dialogs')
</div>
