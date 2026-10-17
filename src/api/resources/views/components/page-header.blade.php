@props(['title', 'subtitle' => null])
<div class="flex items-end gap-4">
    <div class="flex flex-col gap-1 grow min-w-0">
        <h1 class="text-[22px] leading-7 font-semibold tracking-tight">{{ $title }}</h1>
        @if ($subtitle)
            <p class="text-muted">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
