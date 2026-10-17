<div class="flex flex-col gap-4">
    @php
        use App\Enums\DeploymentKind;
        use App\Enums\DeploymentStatus;
        use App\Support\Format;
    @endphp

    @if ($notice)
        <div role="status" class="alert alert-soft alert-success text-[13px]"><x-icon name="check-circle" /> {{ $notice }}</div>
    @endif
    @if ($problem)
        <div role="alert" class="alert alert-soft alert-error text-[13px]"><x-icon name="x-circle" /> {{ $problem }}</div>
    @endif

    @foreach ($targets->filter->needsConversion() as $target)
        <div role="alert" class="alert alert-soft alert-warning text-[13px] items-start" wire:key="convert-{{ $target->id }}">
            <x-icon name="alert" class="mt-0.5" />
            <div class="flex flex-col gap-1 grow">
                <span class="font-semibold">An earlier deployment still runs from {{ $target->path }}</span>
                <span>
                    Its {{ $target->leftoverCount() }} {{ Str::plural('item', $target->leftoverCount()) }} sit in the folder itself, not in a release.
                    This project deploys into releases/, so deploys wait until the folder is converted: its files move into a first release and shared/, and nothing is deleted.
                </span>
            </div>
            <a href="{{ route('projects.convert', [$this->project, $target]) }}" wire:navigate class="btn btn-primary btn-sm">
                <x-icon name="layers" :size="14" /> Review the conversion
            </a>
        </div>
    @endforeach

    <section class="card bg-base-100 border border-line">
        <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
            <h2 class="text-[15px] font-semibold">Targets</h2>
            <span class="text-[13px] text-muted">Every server uses this project’s settings</span>
            @unless ($assigning)
                <button type="button" class="btn btn-sm ml-auto" wire:click="startAssigning"><x-icon name="plus" :size="14" /> Assign to server</button>
            @endunless
        </header>

        @if ($targets->isEmpty())
            <p class="px-4 py-6 text-[13px] text-muted">
                Not on any server yet. Assign it to one, and it deploys into <span class="mono">{folder}/{{ $this->project->slug }}</span> there.
            </p>
        @else
            <table class="table">
                <thead>
                    <tr><th>Server</th><th>Directory</th><th>Current release</th><th>Health</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($targets as $target)
                        @php
                            $current = $target->currentRelease;
                            $active = $target->activeDeployment;
                            $last = $target->latestDeployment;
                        @endphp
                        <tr wire:key="target-{{ $target->id }}">
                            <td>
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('servers.show', $target->server) }}" wire:navigate class="font-semibold hover:underline">{{ $target->server->name }}</a>
                                    <span class="badge badge-sm {{ $target->server->env_tag->badgeClass() }}">{{ $target->server->env_tag->label() }}</span>
                                </div>
                            </td>
                            <td>
                                <span class="mono">{{ $target->path }}</span>
                                <div class="text-xs text-muted">
                                    {{ $target->usesServerDefault() ? 'server default' : 'custom · server default is '.($target->server->default_deploy_path ?? 'not set') }}
                                </div>
                            </td>
                            <td>
                                <div class="flex flex-col items-start gap-1">
                                    @if ($current)
                                        <span class="mono text-[13px]">
                                            {{ $current->inReleasesFolder() ? $current->name.($current->shortSha() ? ' · '.$current->shortSha() : '') : ($current->shortSha() ?? $current->name) }}
                                        </span>
                                    @elseif ($target->needsConversion())
                                        <span class="text-[13px]">Deployed in place, before deploy.me</span>
                                        <a href="{{ route('projects.convert', [$this->project, $target]) }}" wire:navigate class="badge badge-sm badge-soft badge-warning gap-1">
                                            <x-icon name="alert" :size="12" /> Not converted · {{ $target->leftoverCount() }} {{ Str::plural('item', $target->leftoverCount()) }}
                                        </a>
                                    @else
                                        <span class="text-[13px] text-muted">{{ $target->bootstrapped_at ? 'Folders ready, nothing deployed yet' : 'Nothing deployed yet' }}</span>
                                    @endif

                                    @if ($active)
                                        <a href="{{ route('deployments.show', $active) }}" wire:navigate class="badge badge-sm badge-soft badge-info gap-1">
                                            <x-icon name="loader" :size="12" class="animate-spin" />
                                            {{ match ($active->kind) { DeploymentKind::Bootstrap => 'Preparing', DeploymentKind::Rollback => 'Rolling back', DeploymentKind::Unlock => 'Breaking the lock', default => 'Deploying' } }} {{ $active->number() }}
                                        </a>
                                    @elseif ($last?->status === DeploymentStatus::RolledBack)
                                        <a href="{{ route('deployments.show', $last) }}" wire:navigate class="badge badge-sm badge-soft badge-warning gap-1">
                                            <x-icon name="rollback" :size="12" /> Rolled back{{ $last->commit_sha ? ' from '.substr($last->commit_sha, 0, 7) : '' }}
                                        </a>
                                    @elseif ($last && in_array($last->status, [DeploymentStatus::Failed, DeploymentStatus::Stalled], true))
                                        <a href="{{ route('deployments.show', $last) }}" wire:navigate class="badge badge-sm badge-soft badge-error gap-1">
                                            <x-icon name="x-circle" :size="12" /> {{ $last->kind->label() }} {{ $last->number() }} {{ Str::lower($last->status->label()) }}
                                        </a>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-sm {{ $target->health->badgeClass() }}" title="{{ $target->health_detail }}">{{ $target->health->label() }}</span>
                                @if ($target->unhealthy_since)
                                    <div class="text-xs text-error">{{ $target->health_detail }} · for {{ $target->unhealthy_since->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true) }}</div>
                                @elseif ($target->health_checked_at)
                                    <div class="text-xs text-muted">checked {{ $target->health_checked_at->diffForHumans() }}</div>
                                @endif
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-1">
                                    @if ($target->needsConversion())
                                        <a href="{{ route('projects.convert', [$this->project, $target]) }}" wire:navigate class="btn btn-xs gap-1">
                                            <x-icon name="layers" :size="12" /> Convert
                                        </a>
                                    @endif
                                    <button type="button" class="btn btn-xs gap-1"
                                            @disabled($active || $target->needsConversion()) @if ($target->needsConversion()) title="Convert the folder first" @endif
                                            x-data="{ opening: false }" x-bind:class="{ 'btn-disabled': opening }" x-bind:aria-disabled="opening"
                                            x-on:click="if (opening) return; opening = true; setTimeout(() => opening = false, 30000); $wire.dispatch('open-deploy-dialog', { targetId: {{ $target->id }} })"
                                            x-on:deploy-dialog-opened.window="opening = false">
                                        <x-icon name="deploy" :size="12" x-show="! opening" /><x-icon name="loader" :size="12" class="animate-spin" x-show="opening" x-cloak /> Deploy
                                    </button>
                                    <div class="dropdown dropdown-end">
                                        <button type="button" tabindex="0" class="btn btn-xs btn-ghost btn-square" aria-label="More for {{ $target->server->name }}"><x-icon name="more" :size="14" /></button>
                                        <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box border border-line w-52 p-1 shadow-sm z-10">
                                            @if (! $this->project->versionable && ! $current)
                                                <li>
                                                    <button type="button" wire:click="adoptTarget({{ $target->id }})" @disabled($active)>
                                                        <x-icon name="plus" :size="14" /> Adopt what’s running
                                                    </button>
                                                </li>
                                            @endif
                                            @if ($target->canConvert())
                                                <li>
                                                    <a href="{{ route('projects.convert', [$this->project, $target]) }}" wire:navigate>
                                                        <x-icon name="layers" :size="14" /> Convert to releases…
                                                    </a>
                                                </li>
                                            @endif
                                            <li>
                                                <button type="button" wire:click="prepareTarget({{ $target->id }})" @disabled($active)>
                                                    <x-icon name="folder-plus" :size="14" /> {{ $target->bootstrapped_at ? 'Prepare again' : 'Prepare' }}
                                                </button>
                                            </li>
                                            @unless ($active)
                                                <li>
                                                    <button type="button" wire:click="breakLock({{ $target->id }})"
                                                            wire:confirm="Break the deploy lock on {{ $target->server->name }} in {{ $target->path }}? Only do this when you know no deploy is running there.">
                                                        <x-icon name="lock" :size="14" /> Break lock…
                                                    </button>
                                                </li>
                                            @endunless
                                            <li>
                                                <button type="button" class="text-error" wire:click="remove({{ $target->id }})"
                                                        wire:confirm="Remove {{ $target->server->name }} from {{ $this->project->name }}? Nothing on the server changes." aria-label="Remove {{ $target->server->name }}">
                                                    <x-icon name="trash" :size="14" /> Remove
                                                </button>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    @if ($assigning)
        <section class="card bg-base-100 border border-line">
            <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                <h2 class="text-[15px] font-semibold">Assign to server</h2>
                <button type="button" class="btn btn-sm btn-ghost btn-square ml-auto" wire:click="$set('assigning', false)" aria-label="Close"><x-icon name="x" /></button>
            </header>
            <div class="p-4 grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)] gap-4">
                <div class="flex flex-col gap-2">
                    <span class="text-[13px] font-medium">Server</span>
                    @forelse ($choices as $choice)
                        <button type="button" wire:click="pickServer({{ $choice['id'] }})" wire:key="choice-{{ $choice['id'] }}" @disabled($choice['assigned'])
                                @class([
                                    'flex items-center gap-3 rounded-box border p-3 text-left',
                                    'border-primary bg-primary/5' => (int) $serverId === $choice['id'],
                                    'border-line hover:bg-base-200/60' => (int) $serverId !== $choice['id'] && ! $choice['assigned'],
                                    'border-line opacity-60 cursor-default' => $choice['assigned'],
                                ])>
                            <x-icon name="server" class="text-muted" />
                            <span class="flex flex-col min-w-0">
                                <span class="font-semibold">{{ $choice['name'] }}</span>
                                <span class="text-xs text-muted">
                                    {{ $choice['env']->label() }} ·
                                    @if ($choice['assigned'])
                                        already runs this project
                                    @elseif ($choice['offline'])
                                        unreachable at the last probe
                                    @else
                                        default {{ $choice['default'] ?? 'not set' }}{{ $choice['free'] !== null ? ' · '.Format::bytes($choice['free']).' free' : '' }}
                                    @endif
                                </span>
                            </span>
                        </button>
                    @empty
                        <p class="text-[13px] text-muted">Add a server first: <a href="{{ route('servers.create') }}" wire:navigate class="link">Add server</a>.</p>
                    @endforelse
                    @error('serverId')<span class="text-error text-xs">{{ $message }}</span>@enderror
                </div>

                <div class="flex flex-col gap-3">
                    <div class="flex flex-col gap-1.5">
                        <span class="text-[13px] font-medium">Folder</span>
                        <div class="flex gap-2">
                            <input type="text" wire:model.live.debounce.300ms="baseDir" class="input grow font-mono" placeholder="/opt/srv" spellcheck="false" @disabled(! $server)>
                            <button type="button" class="btn btn-square" wire:click="$set('browsing', true)" @disabled(! $server) aria-label="Browse the server’s folders"><x-icon name="folder" /></button>
                        </div>
                        @error('baseDir')<span class="text-error text-xs">{{ $message }}</span>@enderror
                    </div>

                    @if ($path)
                        <div class="rounded-box bg-sunk p-3 font-mono text-[12px] leading-5">
                            <div class="font-semibold">Deploys to {{ $path }}</div>
                            @foreach ($preview as $row)
                                <div><span class="text-muted">{{ $loop->last ? '└─' : '├─' }}</span> {{ $row['name'] }} <span class="text-muted">{{ $row['note'] }}</span></div>
                            @endforeach
                        </div>
                    @endif

                    <label class="flex items-start gap-2.5 text-[13px]">
                        <input type="checkbox" class="checkbox checkbox-sm mt-0.5" wire:model.live="deployAfter">
                        <span>Deploy {{ $this->project->branch }} right after assigning</span>
                    </label>
                    @unless ($deployAfter)
                        <label class="flex items-start gap-2.5 text-[13px]">
                            <input type="checkbox" class="checkbox checkbox-sm mt-0.5" wire:model="prepare">
                            <span>Create the folders{{ $envCount ? ' and upload the '.$envCount.' env '.Str::plural('file', $envCount) : '' }} now</span>
                        </label>
                    @endunless
                </div>
            </div>
            <footer class="flex justify-end gap-2 px-4 py-3 border-t border-line">
                <button type="button" class="btn btn-ghost" wire:click="$set('assigning', false)">Cancel</button>
                <button type="button" class="btn btn-primary" wire:click="assign" wire:loading.attr="disabled" @disabled(! $server)>
                    <x-icon :name="$deployAfter ? 'deploy' : 'check'" /> {{ $deployAfter ? 'Assign and deploy' : 'Assign to '.($server?->name ?? 'server') }}
                </button>
            </footer>
        </section>
    @endif

    @if ($browsing && $server)
        <div class="modal modal-open" role="dialog" aria-label="Choose the folder">
            <div class="modal-box max-w-3xl p-0">
                <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                    <h2 class="text-[15px] font-semibold">Choose the folder</h2>
                    <span class="text-[13px] text-muted mono">{{ $server->ssh_alias }}</span>
                    <button type="button" class="btn btn-sm btn-ghost btn-square ml-auto" wire:click="$set('browsing', false)" aria-label="Close"><x-icon name="x" /></button>
                </header>
                <livewire:remote.folder-browser :alias="$server->ssh_alias" :reserved="$server->reserved_paths ?? []" wire:model="baseDir" :key="'browse-'.$server->id" />
                <div class="flex justify-end gap-2 px-4 py-3 border-t border-line">
                    <button type="button" class="btn btn-primary btn-sm" wire:click="usePath">Use this folder</button>
                </div>
            </div>
            <div class="modal-backdrop" wire:click="$set('browsing', false)"></div>
        </div>
    @endif
</div>
