<div class="flex flex-col gap-4 max-w-4xl">
    <div class="flex items-center gap-2 text-[13px] flex-wrap">
        <span class="text-muted">Add a preset:</span>
        @foreach ($presets as $key => $label)
            <button type="button" class="btn btn-sm" wire:click="addPreset(@js($key))"><x-icon name="plus" :size="12" /> {{ $label }}</button>
        @endforeach
        @if ($migratesItself)
            <span class="text-muted">The presets leave the migrations out: this project runs them as a step of its own, before the release goes live.</span>
        @endif
    </div>

    @if ($notice)
        <div role="status" class="alert alert-soft alert-success text-[13px]"><x-icon name="check-circle" /> {{ $notice }}</div>
    @endif

    @foreach ($phases as $phase)
        @php $items = $hooks->get($phase->value, collect()); @endphp
        <section class="card bg-base-100 border border-line" wire:key="phase-{{ $phase->value }}">
            <header class="flex items-center gap-2.5 min-h-[52px] px-4 py-2 border-b border-line">
                <div class="flex flex-col">
                    <h2 class="text-[15px] font-semibold">{{ $phase->label() }}</h2>
                    <span class="text-[13px] text-muted">{{ $phase->description() }}</span>
                </div>
                <button type="button" class="btn btn-sm ml-auto" wire:click="startAdding(@js($phase->value))"><x-icon name="plus" :size="14" /> Add hook</button>
            </header>

            @foreach ($items as $hook)
                @if ($editing === $hook->id)
                    @include('livewire.projects.partials.hook-form')
                @else
                    <div class="flex items-start gap-3 px-4 py-3 border-b border-line last:border-b-0" wire:key="hook-{{ $hook->id }}">
                        <div class="flex flex-col">
                            <button type="button" class="btn btn-ghost btn-xs btn-square" wire:click="move({{ $hook->id }}, -1)" @disabled($loop->first) aria-label="Move up"><x-icon name="arrow-up" :size="12" /></button>
                            <button type="button" class="btn btn-ghost btn-xs btn-square" wire:click="move({{ $hook->id }}, 1)" @disabled($loop->last) aria-label="Move down"><x-icon name="arrow-up" :size="12" class="rotate-180" /></button>
                        </div>
                        <div class="flex flex-col gap-1 grow min-w-0">
                            <span class="font-semibold">{{ $hook->name }}</span>
                            <code class="mono bg-sunk rounded-md px-2 py-1 whitespace-pre-wrap break-all">{{ $hook->command }}</code>
                            <span class="text-xs text-muted">
                                <span class="mono">{{ $hook->where() }}</span> · timeout {{ $hook->timeout }} s
                                @if ($hook->continue_on_error) · <span class="text-warning">Continues on error</span> @endif
                            </span>
                        </div>
                        <button type="button" class="btn btn-ghost btn-xs btn-square" wire:click="edit({{ $hook->id }})" aria-label="Edit {{ $hook->name }}"><x-icon name="pencil" :size="12" /></button>
                        <button type="button" class="btn btn-ghost btn-xs btn-square text-error" wire:click="delete({{ $hook->id }})"
                                wire:confirm="Remove the “{{ $hook->name }}” hook?" aria-label="Remove {{ $hook->name }}"><x-icon name="trash" :size="12" /></button>
                    </div>
                @endif
            @endforeach

            @if ($formPhase === $phase->value && $editing === null)
                @include('livewire.projects.partials.hook-form')
            @elseif ($items->isEmpty())
                <p class="px-4 py-4 text-[13px] text-muted">No hooks in this phase.</p>
            @endif
        </section>
    @endforeach
</div>
