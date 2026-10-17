<div class="flex flex-col gap-5">
    <div class="flex flex-col gap-2">
        <div class="flex items-center gap-1.5 text-[13px] text-muted">
            <a href="{{ route('servers.index') }}" wire:navigate class="hover:text-base-content">Servers</a>
            <x-icon name="chevron-right" :size="12" /> <span>Add server</span>
        </div>
        <h1 class="text-[22px] leading-7 font-semibold tracking-tight">Add server</h1>
    </div>

    <ul class="steps steps-horizontal text-[13px] self-start w-full max-w-2xl">
        @foreach (['Pick host', 'Connection', 'Deploy path', 'Review'] as $i => $label)
            <li @class(['step', 'step-primary' => $step >= $i + 1])>{{ $label }}</li>
        @endforeach
    </ul>

    @if ($problem)
        <div role="alert" class="alert alert-soft alert-error text-[13px]"><x-icon name="x-circle" /> {{ $problem }}</div>
    @endif

    {{-- ── 1. Pick host ─────────────────────────────────────────────── --}}
    @if ($step === 1)
        <div class="grid grid-cols-5 gap-4">
            <section class="card bg-base-100 border border-line col-span-3">
                <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                    <h2 class="text-[15px] font-semibold">Pick a host</h2>
                    <span class="text-[13px] text-muted">~/.ssh/config · {{ count($this->hosts) }} hosts</span>
                </header>
                <div class="p-4 pb-3">
                    <label class="input w-full"><x-icon name="search" class="text-subtle" />
                        <input type="search" wire:model.live.debounce.200ms="search" placeholder="Filter by alias or address" class="grow">
                    </label>
                </div>
                <div class="border-t border-line">
                    @forelse ($this->visibleHosts as $host)
                        <button type="button" wire:key="host-{{ $host['name'] }}" wire:click="select(@js($host['name']))" @disabled($host['added'])
                                @class(['w-full flex items-center gap-3 px-4 py-3 border-b border-line text-left', 'bg-primary/5' => $alias === $host['name'], 'hover:bg-base-200/60' => ! $host['added'], 'opacity-60 cursor-default' => $host['added']])>
                            <input type="radio" class="radio radio-sm radio-primary" @checked($alias === $host['name']) @disabled($host['added']) tabindex="-1">
                            <span class="mono font-semibold w-40 truncate">{{ $host['name'] }}</span>
                            <span class="mono text-muted grow truncate">{{ $host['hostName'] ?? '—' }}</span>
                            @if ($host['sandbox'])<span class="badge badge-sm badge-soft badge-info">Sandbox</span>@endif
                            @if ($host['git'])<span class="badge badge-sm badge-ghost">Git identity</span>@endif
                            @if ($host['added'])<span class="badge badge-sm badge-ghost">Added</span>@endif
                        </button>
                    @empty
                        <p class="px-4 py-6 text-[13px] text-muted">No hosts match “{{ $search }}”.</p>
                    @endforelse
                </div>
                @if ($this->gitHostCount > 0)
                    <label class="flex items-center gap-2.5 px-4 py-3 text-[13px] bg-base-200/50 rounded-b-box">
                        <input type="checkbox" class="toggle toggle-sm toggle-primary" wire:model.live="showGitHosts">
                        Show git identities
                        <span class="text-xs text-muted">{{ $this->gitHostCount }} {{ Str::plural('host', $this->gitHostCount) }} resolve to a git host</span>
                    </label>
                @endif
            </section>

            <div class="col-span-2 flex flex-col gap-4">
                <section class="card bg-base-100 border border-line">
                    <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                        <h2 class="text-[15px] font-semibold">Resolved settings</h2>
                        @if ($alias)<span class="text-[13px] text-muted mono">ssh -G {{ $alias }}</span>@endif
                    </header>
                    <div class="p-4">
                        @if ($target)
                            <dl class="grid grid-cols-[130px_minmax(0,1fr)] gap-y-2.5 gap-x-4 text-[13px]">
                                <dt class="text-muted">HostName</dt><dd class="mono">{{ $target['hostName'] }}</dd>
                                <dt class="text-muted">User</dt><dd class="mono">{{ $target['user'] }}</dd>
                                <dt class="text-muted">Port</dt><dd class="mono">{{ $target['port'] }}</dd>
                                <dt class="text-muted">IdentityFile</dt><dd class="mono truncate">{{ $target['identityFiles'][0] ?? 'default keys' }}</dd>
                                <dt class="text-muted">IdentitiesOnly</dt><dd class="mono">{{ $target['identitiesOnly'] ? 'yes' : 'no' }}</dd>
                                <dt class="text-muted">ProxyJump</dt><dd class="mono">{{ $target['proxyJump'] ?? 'none' }}</dd>
                            </dl>
                        @else
                            <p class="text-[13px] text-muted">Pick a host to see what ssh will connect to.</p>
                        @endif
                    </div>
                </section>
                <div role="note" class="alert alert-soft alert-info text-[13px] items-start">
                    <x-icon name="info" class="mt-0.5" />
                    <span>Keys are read from your ~/.ssh folder, mounted read-only. If your ssh-agent has no keys loaded, the key files are used directly.</span>
                </div>
            </div>
        </div>
    @endif

    {{-- ── 2. Connection ───────────────────────────────────────────── --}}
    @if ($step === 2)
        <div class="grid grid-cols-5 gap-4">
            <section class="card bg-base-100 border border-line col-span-3">
                <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                    <h2 class="text-[15px] font-semibold">Connection checks</h2>
                    <span class="text-[13px] text-muted mono">{{ $alias }} · {{ $target['hostName'] ?? '' }}</span>
                    <span wire:loading.flex wire:target="runChecks, trustHostKey" class="ml-auto items-center gap-1.5 text-xs text-muted">
                        <x-icon name="loader" :size="12" class="animate-spin" /> Checking
                    </span>
                </header>
                @foreach ($checks as $check)
                    <div wire:key="check-{{ $check['key'] }}" @class(['flex items-start gap-3 px-4 py-3 border-b border-line last:border-b-0', 'bg-warning/10' => $check['state'] === 'pending'])>
                        <x-icon :name="['ok' => 'check-circle', 'warn' => 'alert', 'fail' => 'x-circle', 'info' => 'info', 'pending' => 'fingerprint'][$check['state']]"
                                @class(['mt-0.5', 'text-success' => $check['state'] === 'ok', 'text-warning' => in_array($check['state'], ['warn', 'pending']), 'text-error' => $check['state'] === 'fail', 'text-muted' => $check['state'] === 'info']) />
                        <div class="flex flex-col grow gap-2 min-w-0">
                            <div class="flex items-center gap-3">
                                <span class="text-[13px] font-medium grow">{{ $check['label'] }}</span>
                                <span class="text-xs text-muted">{{ $check['detail'] }}</span>
                            </div>
                            @if ($check['state'] === 'pending' && $hostKey)
                                <p class="text-[13px] text-muted">Compare the fingerprint with <span class="mono whitespace-nowrap">ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub</span> on the server before you trust it.</p>
                                @foreach ($hostKey['keys'] as $key)
                                    <div class="flex items-center gap-3 px-3 py-2.5 rounded-md border border-warning/40 bg-base-100">
                                        <span class="badge badge-sm badge-outline">{{ $key['type'] }}</span>
                                        <span class="mono grow break-all">{{ $key['fingerprint'] }}</span>
                                    </div>
                                @endforeach
                                <div class="flex gap-2">
                                    <button type="button" class="btn btn-sm btn-primary" wire:click="trustHostKey"><x-icon name="shield" :size="14" /> Trust and continue</button>
                                    <a href="{{ route('servers.index') }}" wire:navigate class="btn btn-sm">Cancel</a>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </section>

            <section class="card bg-base-100 border border-line col-span-2">
                <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                    <h2 class="text-[15px] font-semibold">Details</h2>
                    <button type="button" class="btn btn-xs btn-ghost ml-auto" wire:click="runChecks"><x-icon name="refresh" :size="12" /> Run again</button>
                </header>
                <div class="p-3">
                    <pre class="bg-log text-log-content font-mono text-xs leading-[19px] rounded-lg p-4 min-h-64 whitespace-pre-wrap break-all">@foreach ($log as $line){{ $line }}
@endforeach</pre>
                </div>
            </section>
        </div>
    @endif

    {{-- ── 3. Deploy path ──────────────────────────────────────────── --}}
    @if ($step === 3)
        <div class="grid grid-cols-5 gap-4">
            <section class="card bg-base-100 border border-line col-span-3">
                <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                    <h2 class="text-[15px] font-semibold">Default deploy path</h2>
                    <span class="text-[13px] text-muted mono">{{ $alias }}</span>
                </header>
                <livewire:remote.folder-browser :alias="$alias" wire:model="path" />
            </section>

            <section class="card bg-base-100 border border-line col-span-2 self-start">
                <header class="flex items-center min-h-[52px] px-4 border-b border-line"><h2 class="text-[15px] font-semibold">Selected</h2></header>
                <dl class="p-4 grid grid-cols-[130px_minmax(0,1fr)] gap-y-2.5 gap-x-4 text-[13px]">
                    <dt class="text-muted">Default path</dt><dd class="mono font-semibold">{{ $path ?: '—' }}</dd>
                    <dt class="text-muted">Projects land in</dt><dd class="mono">{{ rtrim($path, '/') }}/{project}</dd>
                    @if ($disk)
                        <dt class="text-muted">Filesystem</dt><dd class="mono">{{ $disk['source'] }} · {{ $disk['fstype'] }}</dd>
                        <dt class="text-muted">Free space</dt>
                        <dd class="flex flex-col gap-1.5">
                            <span>{{ \App\Support\Format::bytes($disk['available']) }} free of {{ \App\Support\Format::bytes($disk['size']) }}</span>
                            <x-usage-bar :percent="\App\Platform\Remote\ProbeResult::usedPercent($disk)" />
                        </dd>
                    @endif
                </dl>
                <p class="px-4 pb-4 text-xs text-muted">You can pick a different directory per project when you assign it to this server.</p>
            </section>
        </div>
    @endif

    {{-- ── 4. Review ───────────────────────────────────────────────── --}}
    @if ($step === 4 && $probe)
        @php $result = new \App\Platform\Remote\ProbeResult($probe); @endphp
        <section class="card bg-base-100 border border-line">
            <header class="flex items-center min-h-[52px] px-4 border-b border-line"><h2 class="text-[15px] font-semibold">Name and environment</h2></header>
            <div class="p-4 grid grid-cols-4 gap-4">
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
                <label class="flex flex-col gap-1.5">
                    <span class="text-[13px] font-medium">Default deploy path</span>
                    <input type="text" wire:model="path" class="input w-full font-mono">
                    @error('path')<span class="text-error text-xs">{{ $message }}</span>@enderror
                </label>
                <label class="flex flex-col gap-1.5">
                    <span class="text-[13px] font-medium">Probe</span>
                    <select wire:model="probeInterval" class="select w-full">
                        @foreach ($intervals as $seconds => $label)
                            <option value="{{ $seconds }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </section>

        <section class="card bg-base-100 border border-line">
            <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line"><h2 class="text-[15px] font-semibold">Specs</h2><span class="text-[13px] text-muted">from the probe a moment ago</span></header>
            <div class="p-4">
                <x-spec-cards :specs="$result->specs()" :cpu-usage="$probe['cpu']['usage'] ?? null" :mem-total="$probe['mem']['total'] ?? null"
                              :mem-available="$probe['mem']['available'] ?? null" :disk="$disk" :uptime="$probe['uptime'] ?? null" :load="$probe['cpu']['load'] ?? null" />
            </div>
        </section>

        <section class="card bg-base-100 border border-line">
            <header class="flex items-center min-h-[52px] px-4 border-b border-line"><h2 class="text-[15px] font-semibold">Capabilities</h2></header>
            <div class="p-4"><x-capability-chips :capabilities="$result->capabilities()" /></div>
        </section>
    @endif

    <div class="flex items-center justify-end gap-2.5 border-t border-line pt-4">
        <a href="{{ route('servers.index') }}" wire:navigate class="btn btn-ghost">Cancel</a>
        @if ($step > 1)
            <button type="button" class="btn" wire:click="back">Back</button>
        @endif
        @if ($step === 1)
            <button type="button" class="btn btn-primary" wire:click="toConnection" @disabled(! $target)>Continue <x-icon name="arrow-right" /></button>
        @elseif ($step === 2)
            <button type="button" class="btn btn-primary" wire:click="toPath" @disabled(! $probe)>Continue <x-icon name="arrow-right" /></button>
        @elseif ($step === 3)
            <button type="button" class="btn btn-primary" wire:click="toReview">Continue <x-icon name="arrow-right" /></button>
        @else
            <button type="button" class="btn btn-primary" wire:click="save" wire:loading.attr="disabled"><x-icon name="check" /> Add server</button>
        @endif
    </div>
</div>
