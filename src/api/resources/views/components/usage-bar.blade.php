@props(['percent' => null, 'label' => null])
@php
    // Threshold colours carry state, so they always come with the number beside them.
    $tone = match (true) {
        $percent === null => '',
        $percent >= 90 => 'progress-error',
        $percent >= 75 => 'progress-warning',
        default => 'progress-primary',
    };
@endphp
<div {{ $attributes->merge(['class' => 'flex items-center gap-2.5 text-xs']) }}>
    @if ($label)
        <span class="w-14 text-muted shrink-0">{{ $label }}</span>
    @endif
    @if ($percent === null)
        <span class="grow text-muted">—</span>
    @else
        <progress class="progress {{ $tone }} grow h-1.5" value="{{ $percent }}" max="100"></progress>
        <span class="w-9 text-right tabular-nums shrink-0">{{ round($percent) }}%</span>
    @endif
</div>
