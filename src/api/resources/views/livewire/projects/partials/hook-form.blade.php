<form wire:submit="save" class="flex flex-col gap-3 px-4 py-4 border-b border-line last:border-b-0 bg-base-200/50">
    <div class="grid grid-cols-2 gap-3">
        <label class="flex flex-col gap-1.5">
            <span class="text-[13px] font-medium">Name</span>
            <input type="text" wire:model="hookName" class="input input-sm" placeholder="Migrate database" maxlength="100">
            @error('hookName')<span class="text-error text-xs">{{ $message }}</span>@enderror
        </label>
        <div class="flex flex-col gap-1.5">
            <span class="text-[13px] font-medium">Runs</span>
            <div class="flex gap-2">
                <select wire:model.live="runIn" class="select select-sm grow" aria-label="Where it runs">
                    @foreach ($runIns as $option)
                        <option value="{{ $option->value }}" @disabled($option->needsService() && ! $dockerized)>{{ $option->label() }}</option>
                    @endforeach
                </select>
                @if (\App\Enums\HookRunIn::tryFrom($runIn)?->needsService())
                    @if ($services !== [])
                        <select wire:model="service" class="select select-sm w-44 font-mono" aria-label="Service">
                            <option value="">Service…</option>
                            @foreach ($services as $option)
                                <option value="{{ $option }}">{{ $option }}</option>
                            @endforeach
                        </select>
                    @else
                        <input type="text" wire:model="service" class="input input-sm w-44 font-mono" placeholder="service" aria-label="Service">
                    @endif
                @endif
            </div>
            <span class="text-xs text-muted mono">{{ \App\Enums\HookRunIn::tryFrom($runIn)?->describe($service !== '' ? $service : 'service') }}</span>
            @error('service')<span class="text-error text-xs">{{ $message }}</span>@enderror
        </div>
    </div>
    <label class="flex flex-col gap-1.5">
        <span class="text-[13px] font-medium">Command</span>
        <textarea wire:model="command" rows="2" class="textarea font-mono text-[12px]" placeholder="php artisan migrate --force" spellcheck="false"></textarea>
        @error('command')<span class="text-error text-xs">{{ $message }}</span>@enderror
    </label>
    <div class="flex items-center gap-4">
        <label class="flex items-center gap-2 text-[13px]">
            Timeout <input type="number" min="10" max="3600" wire:model="timeout" class="input input-sm w-24"> s
        </label>
        <label class="flex items-center gap-2 text-[13px]">
            <input type="checkbox" class="checkbox checkbox-sm" wire:model="continueOnError"> Continue the deploy if it fails
        </label>
        <span class="grow"></span>
        @error('timeout')<span class="text-error text-xs">{{ $message }}</span>@enderror
        <button type="button" class="btn btn-sm btn-ghost" wire:click="cancel">Cancel</button>
        <button type="submit" class="btn btn-sm btn-primary">{{ $editing ? 'Save hook' : 'Add hook' }}</button>
    </div>
</form>
