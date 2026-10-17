@props(['values' => [], 'height' => 40, 'max' => 100])
@php
    // One series, so no legend: the card title names it.
    $width = 300;
    $points = array_values(array_filter($values, fn ($v) => $v !== null));
    $count = count($points);
    $line = $count > 1
        ? collect($points)->map(fn ($v, $i) => round($i / ($count - 1) * $width, 1).','.round($height - 2 - min((float) $v, $max) / $max * ($height - 4), 1))->implode(' ')
        : null;
@endphp
@if ($line)
    <svg {{ $attributes->merge(['class' => 'block w-full overflow-visible']) }} height="{{ $height }}" viewBox="0 0 {{ $width }} {{ $height }}" preserveAspectRatio="none" aria-hidden="true">
        <polygon points="0,{{ $height }} {{ $line }} {{ $width }},{{ $height }}" class="fill-primary/10"/>
        <polyline points="{{ $line }}" fill="none" class="stroke-primary" stroke-width="1.5" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round"/>
    </svg>
@else
    <p class="text-xs text-muted flex items-center" style="height: {{ $height }}px">Collecting readings…</p>
@endif
