@props(['model', 'value' => 5, 'max' => 10])
@php
    // A native range input (keyboard, drag, screen readers) styled in app.css so
    // its fill, thumb and the numbers below all share one geometry: the thumb's
    // centre at `--thumb / 2 + (100% - --thumb) * p`.
    $value = max(1, min($max, (int) $value));
    $share = fn (int $i) => round(($i - 1) / ($max - 1), 4);
@endphp
<div {{ $attributes->merge(['class' => 'keep-slider']) }} style="--p: {{ $share($value) }}"
     x-data x-on:input="$el.style.setProperty('--p', ($event.target.value - 1) / {{ $max - 1 }})">
    <input type="range" min="1" max="{{ $max }}" step="1" value="{{ $value }}" wire:model.live="{{ $model }}" aria-label="Releases to keep">
    <div class="keep-slider-labels" aria-hidden="true">
        @for ($i = 1; $i <= $max; $i++)
            <button type="button" tabindex="-1" wire:click="$set('{{ $model }}', {{ $i }})" style="--at: {{ $share($i) }}"
                    @class(['keep-slider-label', 'is-current' => $i === $value])>{{ $i }}</button>
        @endfor
    </div>
</div>
