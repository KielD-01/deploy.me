{{-- A shell pane (App\Livewire\Console\Shell). The prompt is Alpine (consoleShell): history on ↑↓,
     Tab to complete, ↑↓ and Enter to pick when a command asks. --}}
<div class="h-full flex flex-col outline-none" x-data="consoleShell" tabindex="-1" x-on:click="focus()" x-on:keydown="pickKey($event)">
    <div class="term-screen flex-1 overflow-y-auto" x-ref="screen">
        @foreach ($screen as $entry)
            @if ($entry['prompt'] !== null)
                <span class="block"><span class="t-ok">deploy@local</span><span class="t-mute">:</span><span class="t-run">~</span><span class="t-mute">$ </span>{{ $entry['prompt'] }}</span>
            @endif
            @include('livewire.console.partials.lines', ['lines' => $entry['lines']])
        @endforeach

        @if ($ask !== null && $ask['type'] === 'choice')
            <span class="block"><span class="t-run">? </span><span class="t-b">{{ $ask['question'] }}</span><span class="t-faint">  ↑↓ move · enter pick · esc cancel</span></span>
            @foreach ($ask['options'] as $index => $option)
                <span class="block cursor-pointer" x-bind:class="pick === {{ $index }} && 'term-pick'" x-on:click.stop="choose({{ $index }})" x-on:mouseenter="pick = {{ $index }}"><span class="t-run" x-text="pick === {{ $index }} ? '  ❯ ' : '    '">    </span><span class="{{ $option['tone'] ?? '' }}">{{ $option['label'] }}</span>@if (! empty($option['hint']))<span class="t-faint">  {{ $option['hint'] }}</span>@endif</span>
            @endforeach
        @endif

        <label class="flex items-center" x-show="! choosing">
            @if ($ask !== null && $ask['type'] === 'confirm')
                <span class="t-warn">?&nbsp;</span><span class="t-b">{{ $ask['question'] }}&nbsp;</span><span class="t-mute">(y/N)&nbsp;</span>
            @else
                <span class="t-ok">deploy@local</span><span class="t-mute">:</span><span class="t-run">~</span><span class="t-mute">$&nbsp;</span>
            @endif
            <input x-ref="input" x-model="input" class="term-input" autocomplete="off" autocapitalize="off" spellcheck="false" aria-label="Command"
                   x-on:keydown="keydown($event)">
        </label>
        <span class="block t-faint" x-show="hints.length" x-cloak x-text="hints.join('   ')"></span>
    </div>
</div>
