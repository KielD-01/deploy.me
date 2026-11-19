@props(['key', 'seconds' => null, 'label' => null, 'running' => false, 'format' => 'step'])
{{-- A duration that keeps counting in the browser while its step or run is going: the page
     only re-renders when something changes, so the server's figure would stand still. The
     key changes with $running, so a finished one replaces the ticking element. --}}
@if ($running && $seconds !== null)
    <span wire:key="{{ $key }}-running" {{ $attributes }} x-data="elapsed({{ (float) $seconds }}, @js($format))" x-text="label">{{ $label }}</span>
@else
    <span wire:key="{{ $key }}-done" {{ $attributes }}>{{ $label }}</span>
@endif
