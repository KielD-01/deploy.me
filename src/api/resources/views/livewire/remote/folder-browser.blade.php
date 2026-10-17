<div class="flex flex-col">
    @php
        $segments = array_values(array_filter(explode('/', $path)));
    @endphp

    <div class="flex items-center gap-2 px-4 py-3 border-b border-line">
        <button type="button" class="btn btn-sm btn-square" wire:click="up" aria-label="Up one folder"><x-icon name="arrow-up" /></button>
        <button type="button" class="btn btn-sm btn-square" wire:click="refresh" aria-label="Refresh"><x-icon name="refresh" /></button>
        <div class="breadcrumbs text-[13px] font-mono grow py-0 min-w-0">
            <ul>
                <li><button type="button" wire:click="go('/')" class="hover:underline">/</button></li>
                @foreach ($segments as $i => $segment)
                    <li>
                        <button type="button" wire:click="go(@js('/'.implode('/', array_slice($segments, 0, $i + 1))))" @class(['hover:underline', 'font-semibold' => $loop->last])>{{ $segment }}</button>
                    </li>
                @endforeach
            </ul>
        </div>
        <span wire:loading.flex wire:target="open, up, go, refresh, createFolder" class="items-center gap-1.5 text-xs text-muted">
            <x-icon name="loader" :size="12" class="animate-spin" /> Loading
        </span>
        <button type="button" class="btn btn-sm" wire:click="$toggle('creating')"><x-icon name="folder-plus" :size="14" /> New folder</button>
    </div>

    @if ($creating)
        <form wire:submit="createFolder" class="flex items-center gap-2 px-4 py-3 border-b border-line bg-base-200/50">
            <span class="mono text-muted shrink-0">{{ rtrim($path, '/') }}/</span>
            <input type="text" wire:model="newFolder" class="input input-sm grow font-mono" placeholder="srv" autofocus>
            <button type="submit" class="btn btn-sm btn-primary">Create</button>
            <button type="button" class="btn btn-sm btn-ghost" wire:click="$set('creating', false)">Cancel</button>
        </form>
    @endif

    @if ($error)
        <div class="px-4 pt-3">
            <div role="alert" class="alert alert-soft alert-error text-[13px]"><x-icon name="x-circle" /> {{ $error }}</div>
        </div>
    @endif

    @if ($folders === [])
        <p class="px-4 py-6 text-[13px] text-muted">No folders in {{ $path ?: '/' }}. Create one, or use this folder.</p>
    @else
        <table class="table table-sm">
            <thead>
                <tr><th>Name</th><th>Owner</th><th>Mode</th><th></th></tr>
            </thead>
            <tbody>
                @foreach ($folders as $folder)
                    <tr wire:key="folder-{{ md5($path.'/'.$folder['name']) }}" class="hover:bg-base-200/60 cursor-pointer" wire:click="open(@js($folder['name']))">
                        <td>
                            <span class="flex items-center gap-2">
                                <x-icon name="folder" @class(['text-muted' => ! $folder['writable'], 'text-primary' => $folder['writable']]) />
                                <span class="mono">{{ $folder['name'] }}</span>
                            </span>
                        </td>
                        <td class="mono text-muted">{{ $folder['owner'] }}:{{ $folder['group'] }}</td>
                        <td class="mono text-muted">{{ $folder['mode'] }}</td>
                        <td class="text-right">
                            @if ($folder['reserved'])
                                <span class="badge badge-sm badge-soft badge-warning gap-1"><x-icon name="lock" :size="12" /> Reserved</span>
                            @elseif ($folder['writable'])
                                <span class="badge badge-sm badge-soft badge-success gap-1"><x-icon name="check" :size="12" /> Writable</span>
                            @else
                                <span class="text-xs text-muted">Not writable</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if ($truncated)
            <p class="px-4 py-2 text-xs text-muted">Showing the first 1,000 entries.</p>
        @endif
    @endif
</div>
