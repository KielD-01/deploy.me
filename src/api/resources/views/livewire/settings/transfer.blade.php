<div class="flex flex-col gap-5">
    @if ($notice)
        <div role="status" class="alert alert-soft alert-success text-[13px] max-w-4xl"><x-icon name="check-circle" /> {{ $notice }}</div>
    @endif
    @if ($problem)
        <div role="alert" class="alert alert-soft alert-error text-[13px] max-w-4xl"><x-icon name="x-circle" /> {{ $problem }}</div>
    @endif

    @unless ($confirmed)
        <section class="card bg-base-100 border border-line max-w-4xl">
            <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                <h2 class="text-[15px] leading-[22px] font-semibold">Move projects to another machine</h2>
            </header>
            <div class="p-4 flex flex-col gap-3">
                <p class="text-[13px]">
                    Export your projects as one code, then paste it into deploy.me on the other machine: their settings, env files,
                    hooks, actions and targets go across, so nothing is typed in again. The code carries env files, so confirm it’s you first.
                </p>
                <div><button type="button" class="btn btn-primary btn-sm" wire:click="confirm"><x-icon name="fingerprint" :size="14" /> Confirm with a passkey</button></div>
            </div>
        </section>
    @else
        <section class="card bg-base-100 border border-line max-w-4xl">
            <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                <h2 class="text-[15px] leading-[22px] font-semibold">Export</h2>
                <span class="text-[13px] text-muted">Projects as one code, sealed with a passphrase.</span>
            </header>

            @if ($code)
                <div class="p-4 flex flex-col gap-3" x-data="{ copied: false }">
                    <textarea x-ref="code" readonly rows="6" spellcheck="false" class="textarea w-full font-mono text-xs break-all" x-on:focus="$el.select()">{{ $code }}</textarea>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" class="btn btn-primary btn-sm" x-on:click="navigator.clipboard?.writeText($refs.code.value).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                            <x-icon name="copy" :size="14" /> <span x-text="copied ? 'Copied' : 'Copy code'">Copy code</span>
                        </button>
                        <button type="button" class="btn btn-sm" x-on:click="
                            const link = document.createElement('a');
                            link.href = URL.createObjectURL(new Blob([$refs.code.value + '\n'], { type: 'text/plain' }));
                            link.download = 'deployme-projects-{{ now()->forViewer()->format('Y-m-d') }}.txt';
                            link.click();
                            URL.revokeObjectURL(link.href);
                        "><x-icon name="arrow-down" :size="14" /> Save as a file</button>
                        <button type="button" class="btn btn-ghost btn-sm ml-auto" wire:click="$set('code', null)">Done</button>
                    </div>
                    <p class="text-xs text-muted">{{ number_format(strlen($code)) }} characters. Without the passphrase the code says nothing, so it can travel through a chat or a note — send the passphrase some other way.</p>
                </div>
            @elseif ($projects->isEmpty())
                <p class="p-4 text-[13px] text-muted">No projects to export yet.</p>
            @else
                <form wire:submit="export" class="p-4 flex flex-col gap-4">
                    <div class="flex flex-col gap-1.5">
                        <div class="flex items-center gap-2">
                            <span class="text-[13px] font-medium">Projects</span>
                            <button type="button" class="btn btn-ghost btn-xs" wire:click="$set('selected', @js($projects->pluck('slug')->all()))">All</button>
                            <button type="button" class="btn btn-ghost btn-xs" wire:click="$set('selected', [])">None</button>
                        </div>
                        <div class="grid grid-cols-2 gap-x-4 gap-y-1">
                            @foreach ($projects as $project)
                                <label class="flex items-center gap-2 text-[13px] py-0.5" wire:key="export-{{ $project->slug }}">
                                    <input type="checkbox" class="checkbox checkbox-sm" wire:model="selected" value="{{ $project->slug }}">
                                    <span class="truncate">{{ $project->name }}</span>
                                    <span class="text-xs text-muted shrink-0">
                                        {{ $project->targets_count }} {{ Str::plural('target', $project->targets_count) }}@if ($project->env_files_count) · {{ $project->env_files_count }} env @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4 max-w-xl">
                        <label class="flex flex-col gap-1.5">
                            <span class="text-[13px] font-medium">Passphrase</span>
                            <input type="password" wire:model="passphrase" autocomplete="new-password" class="input input-sm">
                        </label>
                        <label class="flex flex-col gap-1.5">
                            <span class="text-[13px] font-medium">Again</span>
                            <input type="password" wire:model="passphraseConfirmation" autocomplete="new-password" class="input input-sm">
                        </label>
                    </div>
                    <p class="text-xs text-muted -mt-2">At least {{ \App\Support\Transfer\TransferCode::MIN_PASSPHRASE }} characters. The other machine asks for it; nobody can open the code without it, deploy.me included.</p>
                    <div class="flex items-center gap-3">
                        <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="export">
                            <x-icon name="lock" :size="14" />
                            <span wire:loading.remove wire:target="export">Make the code</span>
                            <span wire:loading wire:target="export">Sealing…</span>
                        </button>
                        <span class="text-xs text-muted">Goes across: settings, env files, hooks, actions, targets and the releases on them. Stays: runs, logs, GitHub tokens.</span>
                    </div>
                </form>
            @endif
        </section>

        <section class="card bg-base-100 border border-line max-w-4xl">
            <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                <h2 class="text-[15px] leading-[22px] font-semibold">Import</h2>
                <span class="text-[13px] text-muted">A code from another deploy.me. Nothing on the servers changes.</span>
            </header>

            @if ($result)
                <div class="p-4 flex flex-col gap-3">
                    <div role="status" class="alert alert-soft alert-success text-[13px]">
                        <x-icon name="check-circle" />
                        <span>Imported {{ count($result['projects']) }} {{ Str::plural('project', count($result['projects'])) }} and {{ $result['targets'] }} {{ Str::plural('target', $result['targets']) }}.</span>
                    </div>
                    @if ($result['skipped'])
                        <div class="flex flex-col gap-1">
                            <span class="text-[13px] font-medium">Left out</span>
                            <ul class="text-[13px] text-muted list-disc pl-5">
                                @foreach ($result['skipped'] as $reason)
                                    <li>{{ $reason }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    <div class="flex gap-2">
                        <a href="{{ route('projects.index') }}" wire:navigate class="btn btn-primary btn-sm">Open projects</a>
                        <button type="button" class="btn btn-sm" wire:click="cancelImport">Import another</button>
                    </div>
                </div>
            @elseif ($plan !== null)
                @php
                    $new = collect($plan)->where('state', 'new');
                    $adding = collect($plan)->whereIn('state', ['new', 'here'])->sum(fn ($row) => collect($row['targets'])->where('state', 'add')->count());
                    $autoDeploys = $new->where('auto_deploy', true)->count();
                    $importLabel = 'Import '.$new->count().' '.Str::plural('project', $new->count()).($adding ? " and {$adding} ".Str::plural('target', $adding) : '');
                @endphp
                <div class="flex flex-col">
                    <ul class="divide-y divide-line">
                        @foreach ($plan as $row)
                            <li class="px-4 py-3 flex flex-col gap-1.5" wire:key="plan-{{ $loop->index }}">
                                <div class="flex items-center gap-2">
                                    <span class="text-[13px] font-medium">{{ $row['name'] }}</span>
                                    <span class="text-xs text-muted font-mono truncate">{{ $row['source'] }}</span>
                                    <span class="ml-auto shrink-0">
                                        @switch ($row['state'])
                                            @case ('new')
                                                <span class="badge badge-sm badge-soft badge-success">new</span>
                                                @break
                                            @case ('here')
                                                <span class="badge badge-sm badge-soft">already here{{ $row['here'] !== $row['name'] ? ' as '.$row['here'] : '' }}</span>
                                                @break
                                            @default
                                                <span class="badge badge-sm badge-soft badge-error">can’t be read</span>
                                        @endswitch
                                    </span>
                                </div>
                                @if ($row['state'] === 'new')
                                    <span class="text-xs text-muted">
                                        {{ $row['env_files'] }} env {{ Str::plural('file', $row['env_files']) }} ·
                                        {{ $row['hooks'] }} {{ Str::plural('hook', $row['hooks']) }} ·
                                        {{ $row['actions'] }} {{ Str::plural('action', $row['actions']) }}
                                    </span>
                                @endif
                                @foreach ($row['targets'] as $target)
                                    <div class="flex items-start gap-2 text-xs">
                                        @switch ($target['state'])
                                            @case ('add')
                                                <x-icon name="plus" :size="14" class="text-success shrink-0" />
                                                @break
                                            @case ('here')
                                                <x-icon name="check" :size="14" class="text-muted shrink-0" />
                                                @break
                                            @default
                                                <x-icon name="alert" :size="14" class="text-warning shrink-0" />
                                        @endswitch
                                        <span><span class="font-medium">{{ $target['server_name'] }}</span> <span class="font-mono text-muted">{{ $target['path'] }}</span>
                                            @if ($target['state'] === 'here') <span class="text-muted">— already here</span>@endif
                                            @if ($target['reason']) <span class="text-warning">— {{ $target['reason'] }}</span>@endif
                                        </span>
                                    </div>
                                @endforeach
                                @foreach ($row['notes'] as $note)
                                    <div class="flex items-start gap-2 text-xs text-warning"><x-icon name="info" :size="14" class="shrink-0" /> <span>{{ $note }}</span></div>
                                @endforeach
                                @if ($row['reason'])
                                    <span class="text-xs text-error">{{ $row['reason'] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <div class="px-4 py-3 border-t border-line flex flex-col gap-3">
                        @if ($autoDeploys)
                            <label class="flex items-start gap-2 text-[13px]">
                                <input type="checkbox" class="checkbox checkbox-sm mt-0.5" wire:model="keepAutoDeploy">
                                <span>
                                    Keep auto-deploy on for {{ $autoDeploys }} {{ Str::plural('project', $autoDeploys) }}
                                    <span class="block text-xs text-muted">Off unless ticked: while the other machine still runs, both would deploy every green build. Turn it off there first.</span>
                                </span>
                            </label>
                        @endif
                        <div class="flex items-center gap-2">
                            <button type="button" class="btn btn-primary btn-sm" wire:click="import" @disabled($new->isEmpty() && $adding === 0) wire:loading.attr="disabled" wire:target="import">
                                <x-icon name="check" :size="14" />
                                {{ $importLabel }}
                            </button>
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="cancelImport">Cancel</button>
                        </div>
                    </div>
                </div>
            @else
                <form wire:submit="read" class="p-4 flex flex-col gap-4" x-data>
                    <label class="flex flex-col gap-1.5">
                        <span class="text-[13px] font-medium flex items-center gap-2">
                            Code
                            <span class="ml-auto">
                                <input type="file" accept=".txt,text/plain" class="hidden" x-ref="file" x-on:change="
                                    const file = $event.target.files[0];
                                    if (file) file.text().then(text => $wire.set('importCode', text.trim()));
                                    $event.target.value = '';
                                ">
                                <button type="button" class="btn btn-ghost btn-xs" x-on:click="$refs.file.click()"><x-icon name="file" :size="12" /> From a file</button>
                            </span>
                        </span>
                        <textarea wire:model="importCode" rows="5" spellcheck="false" autocomplete="off" class="textarea w-full font-mono text-xs break-all" placeholder="{{ \App\Support\Transfer\TransferCode::PREFIX }}…"></textarea>
                    </label>
                    <label class="flex flex-col gap-1.5 max-w-xs">
                        <span class="text-[13px] font-medium">Passphrase</span>
                        <input type="password" wire:model="importPassphrase" autocomplete="off" class="input input-sm">
                    </label>
                    <div class="flex items-center gap-3">
                        <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="read">
                            <x-icon name="search" :size="14" />
                            <span wire:loading.remove wire:target="read">Read the code</span>
                            <span wire:loading wire:target="read">Opening…</span>
                        </button>
                        <span class="text-xs text-muted">Shows what it would add first. A project already here is left as it is; only targets it lacks are added.</span>
                    </div>
                </form>
            @endif
        </section>
    @endif
</div>
