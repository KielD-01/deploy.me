@props(['status'])
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 text-[13px]']) }}>
    <span class="status status-{{ $status->tone() }}"></span>{{ $status->label() }}
</span>
