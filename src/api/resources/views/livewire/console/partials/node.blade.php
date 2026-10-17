@if (isset($node['id']))
    <section class="tmux-pane" data-pane="{{ $node['id'] }}" style="flex: {{ $size ?? 100 }} 1 0"
             x-bind:class="{ 'is-active': active === @js($node['id']), 'is-zoomed': zoomed === @js($node['id']) }"
             x-on:mousedown="active = @js($node['id'])" aria-label="{{ $node['kind'] }} pane">
        <div class="tmux-title">
            <b>{{ substr($node['id'], 1) }} {{ $node['kind'] }}</b>
            <span>{{ $node['arg'] ?? '' }}</span>
            <span class="ml-auto flex gap-0.5">
                <button type="button" wire:click="split(@js($node['id']), 'row')" title="Split right (⌃b %)" aria-label="Split right">%</button>
                <button type="button" wire:click="split(@js($node['id']), 'col')" title="Split down (⌃b &quot;)" aria-label="Split down">"</button>
                <button type="button" x-on:click="zoom(@js($node['id']))" title="Zoom (⌃b z)" aria-label="Zoom">z</button>
                @unless ($only)
                    <button type="button" wire:click="close(@js($node['id']))" title="Close (⌃b x)" aria-label="Close">x</button>
                @endunless
            </span>
        </div>
        <div class="tmux-content">
            @livewire('console.'.$node['kind'], ['arg' => $node['arg'], 'pane' => $node['id']], key($node['id'].'-'.$node['kind'].'-'.($node['arg'] ?? '')))
        </div>
    </section>
@else
    <div class="tmux-split" data-dir="{{ $node['dir'] }}" style="flex: {{ $size ?? 100 }} 1 0" data-path="{{ implode('.', $path) }}">
        @foreach ($node['children'] as $index => $child)
            @if ($index > 0)
                <div class="tmux-handle" role="separator" aria-orientation="{{ $node['dir'] === 'row' ? 'vertical' : 'horizontal' }}"
                     x-on:mousedown.prevent="drag($event, @js($path), {{ $index }})"></div>
            @endif
            @include('livewire.console.partials.node', ['node' => $child, 'path' => [...$path, $index], 'size' => $node['sizes'][$index] ?? 50, 'only' => $only])
        @endforeach
    </div>
@endif
