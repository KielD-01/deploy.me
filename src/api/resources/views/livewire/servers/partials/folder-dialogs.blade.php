{{-- Browse files, and pick the default deploy path: the server page's two folder dialogs. --}}
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
