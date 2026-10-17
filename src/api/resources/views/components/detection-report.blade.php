@props(['detection'])
@php $items = \App\Support\DetectionReport::items($detection); @endphp
<ul {{ $attributes->merge(['class' => 'flex flex-col']) }}>
    @foreach ($items as $item)
        <li class="flex gap-3 px-4 py-3 border-b border-line last:border-b-0">
            <span @class([
                'size-7 rounded-full grid place-items-center shrink-0',
                'bg-success/10 text-success' => $item['tone'] === 'ok',
                'bg-warning/10 text-warning' => $item['tone'] === 'warning',
                'bg-sunk text-muted' => $item['tone'] === 'info',
            ])>
                <x-icon :name="$item['icon']" :size="14" />
            </span>
            <div class="flex flex-col min-w-0">
                <span class="font-semibold break-words">{{ $item['title'] }}</span>
                <span class="text-[13px] text-muted break-words">{{ $item['detail'] }}</span>
            </div>
        </li>
    @endforeach
</ul>
