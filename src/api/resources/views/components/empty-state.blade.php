@props(['icon' => 'info', 'title'])
<section {{ $attributes->merge(['class' => 'card bg-base-100 border border-line']) }}>
    <div class="card-body items-center text-center gap-3 py-14">
        <span class="size-10 rounded-full bg-sunk text-muted grid place-items-center">
            <x-icon :name="$icon" :size="20" />
        </span>
        <h2 class="text-[15px] leading-[22px] font-semibold">{{ $title }}</h2>
        <p class="text-muted max-w-md">{{ $slot }}</p>
        @isset($actions)
            <div class="flex gap-2 mt-2">{{ $actions }}</div>
        @endisset
    </div>
</section>
