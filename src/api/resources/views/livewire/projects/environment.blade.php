<div class="grid grid-cols-[280px_minmax(0,1fr)] gap-4 items-start max-w-6xl">
    <section class="card bg-base-100 border border-line">
        <header class="flex items-center min-h-[52px] px-4 border-b border-line">
            <h2 class="text-[15px] font-semibold">Files</h2>
            <button type="button" class="btn btn-xs ml-auto" wire:click="startAdding"><x-icon name="plus" :size="12" /> Add</button>
        </header>
        <ul class="flex flex-col">
            @forelse ($files as $item)
                <li wire:key="env-file-{{ $item->id }}">
                    <button type="button" wire:click="select({{ $item->id }})"
                            @class(['w-full flex items-center gap-2.5 px-4 py-3 border-b border-line text-left', 'bg-primary/5' => $file?->id === $item->id && ! $adding, 'hover:bg-base-200/60' => $file?->id !== $item->id])>
                        <x-icon name="file" class="text-muted" />
                        <span class="flex flex-col min-w-0">
                            <span class="mono font-semibold truncate">{{ $item->path }}</span>
                            <span class="text-xs text-muted">{{ $item->role->label() }} · {{ $item->key_count }} {{ Str::plural('key', $item->key_count) }}</span>
                        </span>
                    </button>
                </li>
            @empty
                <li class="px-4 py-4 text-[13px] text-muted">No env files yet.</li>
            @endforelse
        </ul>
        @if ($targets->isNotEmpty())
            <footer class="flex flex-col gap-2 px-4 py-3">
                <p class="text-xs text-muted">Already deployed? Take the files the project runs with on the server.</p>
                @if ($targets->count() > 1)
                    <select wire:model="targetId" class="select select-sm" aria-label="Server to import from">
                        @foreach ($targets as $target)
                            <option value="{{ $target->id }}">{{ $target->server->name }}</option>
                        @endforeach
                    </select>
                @endif
                <button type="button" class="btn btn-xs" wire:click="importFromServer" wire:target="importFromServer" wire:loading.attr="disabled">
                    <x-icon name="arrow-down" :size="12" />
                    Import from {{ $targets->count() > 1 ? 'the server' : $targets->first()->server->name }}
                </button>
            </footer>
        @endif
    </section>

    <section class="card bg-base-100 border border-line">
        @if ($notice)
            <div class="px-4 pt-4"><div role="status" class="alert alert-soft alert-success text-[13px]"><x-icon name="check-circle" /> {{ $notice }}</div></div>
        @endif
        @if ($problem)
            <div class="px-4 pt-4"><div role="alert" class="alert alert-soft alert-warning text-[13px]"><x-icon name="alert" /> {{ $problem }}</div></div>
        @endif

        @if ($adding)
            <form wire:submit="addFile" class="p-4 flex flex-col gap-3">
                <h2 class="text-[15px] font-semibold">Add an env file</h2>
                <div class="grid grid-cols-2 gap-3">
                    <label class="flex flex-col gap-1.5">
                        <span class="text-[13px] font-medium">Path</span>
                        <input type="text" wire:model="newPath" placeholder="src/api/.env" class="input input-sm font-mono" spellcheck="false">
                        @error('newPath')<span class="text-error text-xs">{{ $message }}</span>@enderror
                    </label>
                    <label class="flex flex-col gap-1.5">
                        <span class="text-[13px] font-medium">Role</span>
                        <select wire:model="newRole" class="select select-sm">
                            @foreach ($roles as $role)
                                <option value="{{ $role->value }}">{{ $role->label() }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                @if ($templates !== [])
                    <label class="flex flex-col gap-1.5">
                        <span class="text-[13px] font-medium">Start from</span>
                        <select wire:model="template" class="select select-sm">
                            <option value="">An empty file</option>
                            @foreach ($templates as $template)
                                <option value="{{ $template['path'] }}">{{ $template['path'] }} ({{ $template['keys'] }} {{ Str::plural('key', $template['keys']) }})</option>
                            @endforeach
                        </select>
                        @error('template')<span class="text-error text-xs">{{ $message }}</span>@enderror
                    </label>
                @endif
                <p class="text-xs text-muted">
                    The path is relative to the deploy folder. Compose root is the .env next to the compose file; App environment is what the application reads.
                </p>
                <div class="flex justify-end gap-2">
                    <button type="button" class="btn btn-sm btn-ghost" wire:click="$set('adding', false)">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary"><x-icon name="plus" :size="14" /> Add file</button>
                </div>
            </form>
        @elseif ($file === null)
            <p class="px-4 py-6 text-[13px] text-muted">
                Add the env files your servers need, like .env for Compose or src/api/.env for the app.
                They’re encrypted at rest and shipped with every deploy.
            </p>
        @else
            <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                <h2 class="mono text-[15px] font-semibold">{{ $file->path }}</h2>
                <span class="text-[13px] text-muted">
                    v{{ $file->version }} · saved {{ $file->updated_at->forViewer()->toFormattedDateString() }}
                    @if ($versions) · {{ $versions }} earlier {{ Str::plural('version', $versions) }} kept @endif
                </span>
                @unless ($editing)
                    <div class="ml-auto flex gap-2">
                        @unless ($revealed)
                            <button type="button" class="btn btn-sm" wire:click="reveal"><x-icon name="eye" :size="14" /> Reveal secrets</button>
                        @endunless
                        <button type="button" class="btn btn-sm" wire:click="edit"><x-icon name="pencil" :size="14" /> Edit</button>
                    </div>
                @endunless
            </header>

            @if ($editing)
                <form wire:submit="save">
                    <textarea wire:model="content" rows="18" spellcheck="false" aria-label="{{ $file->path }}"
                              class="textarea w-full font-mono text-[12px] leading-5 rounded-none border-0 border-b border-line focus:outline-none"></textarea>
                    @error('content')<p class="px-4 pt-2 text-error text-xs">{{ $message }}</p>@enderror
                    <div class="flex items-center gap-2 px-4 py-3">
                        <span class="text-xs text-muted inline-flex items-center gap-1.5"><x-icon name="lock" :size="12" /> Encrypted at rest when saved</span>
                        <span class="grow"></span>
                        <button type="button" class="btn btn-sm btn-ghost" wire:click="cancelEdit">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-primary"><x-icon name="check" :size="14" /> Save as v{{ $file->version + 1 }}</button>
                    </div>
                </form>
            @else
                @if ($keys === [])
                    <p class="px-4 py-6 text-[13px] text-muted">This file is empty. Edit it to add keys.</p>
                @else
                    <table class="table table-sm">
                        <thead><tr><th class="w-2/5">Key</th><th>Value</th></tr></thead>
                        <tbody>
                            @foreach ($keys as $key => $value)
                                <tr wire:key="key-{{ md5($key) }}">
                                    <td class="mono">{{ $key }}</td>
                                    <td class="mono">
                                        @if ($value === '')
                                            <span class="text-muted">empty</span>
                                        @elseif (\App\Support\DotEnv::isSecret($key) && ! $revealed)
                                            <span class="text-muted">••••••••••••</span> <span class="badge badge-xs badge-ghost ml-1">Secret</span>
                                        @else
                                            <span class="break-all">{{ $value }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
                <footer class="flex items-center gap-2 px-4 py-3 border-t border-line text-xs text-muted">
                    <x-icon name="lock" :size="12" /> Values are encrypted at rest. Revealing or editing asks for your passkey.
                    <span class="grow"></span>
                    <button type="button" class="btn btn-xs btn-ghost text-error" wire:click="deleteFile"
                            wire:confirm="Remove {{ $file->path }} and its earlier versions from deploy.me? Servers keep their copy.">Remove file</button>
                </footer>
            @endif
        @endif
    </section>
</div>
