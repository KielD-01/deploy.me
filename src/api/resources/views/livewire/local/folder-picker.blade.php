<div class="flex flex-col">
    <div class="flex items-center gap-2 px-4 py-3 border-b border-line">
        <button type="button" class="btn btn-sm btn-square" wire:click="up" @disabled($path === '') aria-label="Up one folder"><x-icon name="arrow-up" /></button>
        <span class="mono grow truncate">{{ $path === '' ? 'Project folders' : $display }}</span>
        <span wire:loading.flex wire:target="open, up, go" class="items-center gap-1.5 text-xs text-muted">
            <x-icon name="loader" :size="12" class="animate-spin" /> Loading
        </span>
    </div>

    @if ($path === '')
        <ul class="flex flex-col">
            @foreach ($roots as $root)
                <li wire:key="root-{{ md5($root['path']) }}">
                    <button type="button" wire:click="go(@js($root['path']))" @disabled(! $root['exists'])
                            @class(['w-full flex items-center gap-2.5 px-4 py-2.5 border-b border-line text-left', 'hover:bg-base-200/60' => $root['exists'], 'opacity-60' => ! $root['exists']])>
                        <x-icon name="folder" class="text-primary" />
                        <span class="mono grow">{{ \App\Support\Format::homePath($root['path']) }}</span>
                        @unless ($root['exists'])<span class="text-xs text-muted">Not mounted</span>@endunless
                    </button>
                </li>
            @endforeach
        </ul>
    @elseif ($folders === [])
        <p class="px-4 py-6 text-[13px] text-muted">No folders in here. Use this folder, or go up.</p>
    @else
        <ul class="flex flex-col max-h-[420px] overflow-y-auto">
            @foreach ($folders as $folder)
                <li wire:key="folder-{{ md5($folder['path']) }}">
                    <button type="button" wire:click="open(@js($folder['name']))" class="w-full flex items-center gap-2.5 px-4 py-2.5 border-b border-line text-left hover:bg-base-200/60">
                        <x-icon name="folder" @class(['text-primary' => $folder['git'], 'text-muted' => ! $folder['git']]) />
                        <span class="mono grow truncate">{{ $folder['name'] }}</span>
                        @if (in_array($folder['path'], $imported, true))
                            <span class="badge badge-sm badge-soft badge-success">Imported</span>
                        @elseif ($folder['git'])
                            <span class="badge badge-sm badge-ghost gap-1"><x-icon name="branch" :size="12" /> Git</span>
                        @endif
                    </button>
                </li>
            @endforeach
        </ul>
    @endif
</div>
