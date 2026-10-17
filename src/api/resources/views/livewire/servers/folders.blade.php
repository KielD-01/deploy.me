<div class="flex flex-col gap-4 max-w-5xl">
    @php
        use App\Enums\SyncDirection;
        use App\Enums\SyncRunStatus;
        use App\Support\Format;
        $kinds = ['new' => ['+ new', 'text-success'], 'updated' => ['~ update', 'text-info'], 'deleted' => ['− delete', 'text-error'], 'folder' => ['+ folder', 'text-muted'], 'link' => ['+ link', 'text-muted']];
    @endphp

    @if ($notice)
        <div role="status" class="alert alert-soft alert-success text-[13px]"><x-icon name="check-circle" /> {{ $notice }}</div>
    @endif
    @if ($problem)
        <div role="alert" class="alert alert-soft alert-error text-[13px]"><x-icon name="x-circle" /> {{ $problem }}</div>
    @endif

    <section class="card bg-base-100 border border-line">
        <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
            <h2 class="text-[15px] font-semibold">Folder mappings</h2>
            <span class="text-[13px] text-muted">One direction each · rsync over SSH</span>
            @unless ($formOpen)
                <button type="button" class="btn btn-sm ml-auto" wire:click="startAdding"><x-icon name="plus" :size="14" /> Add mapping</button>
            @endunless
        </header>

        @forelse ($mappings as $mapping)
            @php
                $last = $mapping->lastRealRun;
                $active = $mapping->activeRun;
                $push = $mapping->direction === SyncDirection::Push;
            @endphp
            <div class="flex items-center gap-3 px-4 py-3 border-b border-line last:border-b-0" wire:key="mapping-{{ $mapping->id }}">
                <span @class(['size-[30px] rounded-full grid place-items-center shrink-0', 'bg-primary/10 text-primary' => $push, 'bg-success/10 text-success' => ! $push])>
                    <x-icon :name="$mapping->direction->icon()" />
                </span>
                <div class="flex flex-col min-w-0 grow">
                    <span class="flex items-center gap-2 min-w-0">
                        <span class="font-semibold truncate">{{ $mapping->name }}</span>
                        <span @class(['badge badge-sm badge-soft shrink-0', 'badge-primary' => $push, 'badge-success' => ! $push])>{{ $mapping->direction->label() }} · {{ Str::lower($mapping->scheduleLabel()) }}</span>
                        @if ($mapping->delete_extraneous)
                            <span class="badge badge-sm badge-soft badge-warning gap-1 shrink-0"><x-icon name="trash" :size="12" /> Deletes</span>
                        @endif
                    </span>
                    <span class="mono text-muted truncate" title="{{ $mapping->routeLabel() }}">{{ $mapping->routeLabel() }}</span>
                    <span class="text-xs text-muted">
                        @if ($active)
                            <span class="text-info inline-flex items-center gap-1"><x-icon name="loader" :size="12" class="animate-spin" /> {{ $active->dry_run ? 'Previewing' : 'Syncing' }}…</span>
                        @elseif (! $last)
                            Never synced
                        @elseif ($last->status === SyncRunStatus::Failed)
                            <span class="text-error">Last sync failed {{ $last->finished_at?->diffForHumans() }}: {{ $last->error }}</span>
                        @elseif ($last->status === SyncRunStatus::Cancelled)
                            Last sync cancelled {{ $last->finished_at?->diffForHumans() }}
                        @else
                            Last sync {{ $last->finished_at?->diffForHumans() }} · {{ $last->summary() }}
                        @endif
                        @if ($mapping->schedule_interval && $mapping->next_run_at)
                            · next {{ $mapping->next_run_at->diffForHumans() }}
                        @endif
                        @php $latest = $mapping->latestRun; @endphp
                        @if (! $active && $latest?->dry_run && $latest->status === SyncRunStatus::Succeeded && $latest->id !== $previewId && $latest->finished_at?->gt(now()->subHour()))
                            · <button type="button" class="link link-hover text-primary" wire:click="showRun({{ $latest->id }})">Review the last preview</button>
                        @endif
                    </span>
                </div>
                @if ($active)
                    <button type="button" class="btn btn-sm" wire:click="cancelRun({{ $active->id }})"><x-icon name="stop" :size="14" /> Cancel</button>
                @else
                    <button type="button" class="btn btn-sm" wire:click="preview({{ $mapping->id }})">Preview</button>
                    <button type="button" class="btn btn-sm" wire:click="syncNow({{ $mapping->id }})">Sync now</button>
                @endif
                <button type="button" class="btn btn-sm btn-ghost btn-square" wire:click="edit({{ $mapping->id }})" @disabled($active) aria-label="Edit {{ $mapping->name }}"><x-icon name="pencil" :size="14" /></button>
                <button type="button" class="btn btn-sm btn-ghost btn-square text-error" wire:click="remove({{ $mapping->id }})"
                        wire:confirm="Remove {{ $mapping->name }}? Files on both sides stay where they are." aria-label="Remove {{ $mapping->name }}"><x-icon name="trash" :size="14" /></button>
            </div>
        @empty
            @unless ($formOpen)
                <p class="px-4 py-6 text-[13px] text-muted">
                    Pair a folder on your Mac with one on {{ $this->server->name }}: push uploads up, pull backups down.
                    Each mapping syncs one way, and nothing is deleted without a preview first.
                </p>
            @endunless
        @endforelse
    </section>

    @if ($formOpen)
        <section class="card bg-base-100 border border-line">
            <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                <h2 class="text-[15px] font-semibold">{{ $editing ? 'Edit mapping' : 'Add mapping' }}</h2>
                <button type="button" class="btn btn-sm btn-ghost btn-square ml-auto" wire:click="$set('formOpen', false)" aria-label="Close"><x-icon name="x" /></button>
            </header>
            <div class="p-4 flex flex-col gap-4">
                <div class="flex items-center gap-3">
                    <div class="join">
                        @foreach (SyncDirection::cases() as $option)
                            <button type="button" wire:click="$set('direction', @js($option->value))" @class(['btn btn-sm join-item gap-1.5', 'btn-active' => $direction === $option->value])>
                                <x-icon :name="$option->icon()" :size="14" /> {{ $option->label() }}: {{ $option->describe() }}
                            </button>
                        @endforeach
                    </div>
                    <label class="flex flex-col gap-1 grow max-w-xs">
                        <input type="text" wire:model="name" class="input input-sm" placeholder="Name, like Uploads" maxlength="100" aria-label="Name">
                        @error('name')<span class="text-error text-xs">{{ $message }}</span>@enderror
                    </label>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="flex flex-col gap-1.5">
                        <span class="text-[13px] font-medium">Folder on your Mac</span>
                        <div class="flex gap-2">
                            <input type="text" wire:model.blur="localPath" class="input input-sm grow font-mono" placeholder="~/Claude/app/storage/uploads" spellcheck="false">
                            <button type="button" class="btn btn-sm btn-square" wire:click="browseLocal" aria-label="Browse your folders"><x-icon name="folder" :size="14" /></button>
                        </div>
                        @error('localPath')<span class="text-error text-xs">{{ $message }}</span>@enderror
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <span class="text-[13px] font-medium">Folder on the server</span>
                        <div class="flex gap-2">
                            <input type="text" wire:model.blur="remotePath" class="input input-sm grow font-mono" placeholder="/opt/srv/app/shared/storage/uploads" spellcheck="false">
                            <button type="button" class="btn btn-sm btn-square" wire:click="browseRemote" wire:loading.attr="disabled" wire:target="browseRemote" aria-label="Browse the server"><x-icon name="folder" :size="14" /></button>
                        </div>
                        @error('remotePath')<span class="text-error text-xs">{{ $message }}</span>@enderror
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <label class="flex flex-col gap-1.5">
                        <span class="text-[13px] font-medium">Leave out</span>
                        <textarea wire:model="excludes" rows="3" class="textarea textarea-sm font-mono text-[12px]" placeholder="*.tmp&#10;.DS_Store" spellcheck="false"></textarea>
                        <span class="text-xs text-muted">One rsync pattern per line.</span>
                        @error('excludes')<span class="text-error text-xs">{{ $message }}</span>@enderror
                    </label>
                    <div class="flex flex-col gap-3">
                        <label class="flex flex-col gap-1.5">
                            <span class="text-[13px] font-medium">When</span>
                            <select wire:model="schedule" class="select select-sm">
                                @foreach ($schedules as $seconds => $label)
                                    <option value="{{ $seconds }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="flex items-start gap-2 text-[13px]">
                            <input type="checkbox" class="checkbox checkbox-sm mt-0.5" wire:model.live="deleteExtraneous">
                            <span>
                                Delete files that aren’t in the source <x-icon name="lock" :size="12" class="inline text-muted" />
                                <span class="block text-xs text-muted">Asks for your passkey. Every run with deleting starts with a preview.</span>
                            </span>
                        </label>
                    </div>
                </div>
                <p class="text-xs text-muted">The destination folder is created if it’s missing.</p>
            </div>
            <footer class="flex justify-end gap-2 px-4 py-3 border-t border-line">
                <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('formOpen', false)">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" wire:click="save" wire:loading.attr="disabled" wire:target="save">Save and preview</button>
            </footer>
        </section>
    @endif

    @if ($preview)
        <section class="card bg-base-100 border border-line" wire:key="preview-{{ $preview->id }}">
            <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                <h2 class="text-[15px] font-semibold">Dry run · {{ $preview->mapping->name }}</h2>
                @if ($preview->status === SyncRunStatus::Succeeded)
                    @php $counts = $preview->counts(); @endphp
                    <span class="text-[13px] text-muted">
                        @if ($preview->change_count === 0)
                            Nothing to change: both folders already match.
                        @else
                            {{ $preview->change_count }} {{ Str::plural('change', $preview->change_count) }}:
                            {{ implode(', ', array_filter([
                                $counts['new'] ? $counts['new'].' new' : null,
                                $counts['updated'] ? $counts['updated'].' updated' : null,
                                $counts['links'] ? $counts['links'].' '.Str::plural('link', $counts['links']) : null,
                                $counts['deleted'] ? $counts['deleted'].' deleted' : null,
                            ])) }}
                            · {{ Format::bytes((int) ($preview->stats['transferred_bytes'] ?? 0)) }} to send
                        @endif
                    </span>
                @endif
                <button type="button" class="btn btn-sm btn-ghost btn-square ml-auto" wire:click="discard" aria-label="Close the preview"><x-icon name="x" /></button>
            </header>

            @if ($preview->isActive())
                <p class="px-4 py-6 text-[13px] text-muted inline-flex items-center gap-2"><x-icon name="loader" class="animate-spin" /> Comparing the two folders…</p>
            @elseif ($preview->status !== SyncRunStatus::Succeeded)
                <div class="p-4"><div role="alert" class="alert alert-soft alert-error text-[13px]"><x-icon name="x-circle" /> {{ $preview->error ?? 'The preview didn’t finish.' }}</div></div>
            @else
                @php
                    $listed = array_slice($preview->changes ?? [], 0, 200);
                    $total = array_sum($preview->counts());
                @endphp
                @if ($listed !== [])
                    <ul class="px-4 py-3 font-mono text-[12px] flex flex-col gap-1 max-h-[360px] overflow-y-auto">
                        @foreach ($listed as $change)
                            <li class="flex gap-3 min-w-0">
                                <span class="w-16 shrink-0 {{ $kinds[$change['kind']][1] ?? 'text-muted' }}">{{ $kinds[$change['kind']][0] ?? $change['kind'] }}</span>
                                <span class="truncate">{{ $change['path'] }}</span>
                                @if ($change['size'] > 0)<span class="text-muted shrink-0">· {{ Format::bytes($change['size']) }}</span>@endif
                            </li>
                        @endforeach
                        @if ($total > count($listed))
                            <li class="text-muted">… {{ $total - count($listed) }} more</li>
                        @endif
                    </ul>
                @endif
                <footer class="flex items-center gap-2 px-4 py-3 border-t border-line">
                    <span class="text-[13px] text-muted">Nothing has changed yet.</span>
                    <button type="button" class="btn btn-sm ml-auto" wire:click="discard">Discard</button>
                    @if ($preview->change_count > 0)
                        <button type="button" class="btn btn-sm btn-primary" wire:click="apply">Apply {{ $preview->change_count }} {{ Str::plural('change', $preview->change_count) }}</button>
                    @endif
                </footer>
            @endif
        </section>
    @endif

    @if ($browsingLocal || $browsingRemote)
        <div class="modal modal-open" role="dialog" aria-label="Choose a folder">
            <div class="modal-box max-w-3xl p-0">
                <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                    <h2 class="text-[15px] font-semibold">{{ $browsingLocal ? 'Folder on your Mac' : 'Folder on '.$this->server->name }}</h2>
                    <button type="button" class="btn btn-sm btn-ghost btn-square ml-auto" wire:click="closeBrowser" aria-label="Close"><x-icon name="x" /></button>
                </header>
                @if ($browsingLocal)
                    <livewire:local.folder-picker wire:model="localPath" :key="'local-picker-'.$serverId" />
                @else
                    <livewire:remote.folder-browser :alias="$this->server->ssh_alias" :reserved="$this->server->reserved_paths ?? []" wire:model="remotePath" :key="'remote-picker-'.$serverId" />
                @endif
                <div class="flex justify-end gap-2 px-4 py-3 border-t border-line">
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="closeBrowser">Cancel</button>
                    <button type="button" class="btn btn-primary btn-sm" wire:click="usePaths">Use this folder</button>
                </div>
            </div>
            <div class="modal-backdrop" wire:click="closeBrowser"></div>
        </div>
    @endif
</div>
