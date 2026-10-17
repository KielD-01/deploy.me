@props([
    'specs' => [],
    'cpuUsage' => null,
    'memTotal' => null,
    'memAvailable' => null,
    'disk' => null,
    'uptime' => null,
    'load' => null,
])
@php
    use App\Support\Format;

    $memUsed = $memTotal ? round(100 * (1 - $memAvailable / $memTotal), 1) : null;
    $diskUsed = ($disk['size'] ?? 0) > 0 ? round(100 * $disk['used'] / $disk['size'], 1) : null;
    $cores = $specs['cores'] ?? null;
    $threads = $specs['threads'] ?? null;
    $cpuLine = collect([$specs['cpu_model'] ?? null, Format::mhz($specs['mhz_max'] ?? null)])->filter()->implode(' · ') ?: ($specs['arch'] ?? 'CPU');
@endphp
<div {{ $attributes->merge(['class' => 'grid grid-cols-4 gap-4']) }}>
    <div class="card bg-base-100 border border-line p-4 gap-2">
        <span class="flex items-center gap-1.5 text-[13px] text-muted"><x-icon name="cpu" /> CPU</span>
        <span class="text-xl leading-7 font-semibold">
            {{ $cores && $threads && $cores !== $threads ? "{$cores} cores · {$threads} threads" : ($threads ? "{$threads} ".Str::plural('core', $threads) : '—') }}
        </span>
        <span class="text-xs text-muted truncate">{{ $cpuLine }}</span>
        <x-usage-bar :percent="$cpuUsage" />
    </div>
    <div class="card bg-base-100 border border-line p-4 gap-2">
        <span class="flex items-center gap-1.5 text-[13px] text-muted"><x-icon name="memory" /> Memory</span>
        <span class="text-xl leading-7 font-semibold">{{ Format::bytes($memTotal) }}</span>
        <span class="text-xs text-muted">{{ $memAvailable !== null ? Format::bytes($memAvailable).' available' : '—' }}</span>
        <x-usage-bar :percent="$memUsed" />
    </div>
    <div class="card bg-base-100 border border-line p-4 gap-2">
        <span class="flex items-center gap-1.5 text-[13px] text-muted"><x-icon name="disk" /> Disk {{ $disk['mount'] ?? '/' }}</span>
        <span class="text-xl leading-7 font-semibold">{{ Format::bytes($disk['size'] ?? null) }}</span>
        <span class="text-xs text-muted">{{ $disk ? Format::bytes($disk['available']).' free · '.$disk['fstype'] : '—' }}</span>
        <x-usage-bar :percent="$diskUsed" />
    </div>
    <div class="card bg-base-100 border border-line p-4 gap-2">
        <span class="flex items-center gap-1.5 text-[13px] text-muted"><x-icon name="server" /> System</span>
        <span class="text-xl leading-7 font-semibold truncate">{{ $specs['os'] ?? '—' }}</span>
        <span class="text-xs text-muted truncate">kernel {{ $specs['kernel'] ?? '—' }} · {{ $specs['arch'] ?? '—' }}</span>
        <span class="text-xs text-muted">up {{ Format::uptime($uptime) }}@if ($load) · load {{ implode(' ', array_map(fn ($l) => number_format((float) $l, 2), $load)) }}@endif</span>
    </div>
</div>
