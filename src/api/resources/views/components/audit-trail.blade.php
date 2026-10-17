@props(['entries', 'link' => null, 'title' => 'Recent activity'])

@php use App\Enums\AuditOutcome; @endphp

<section {{ $attributes->merge(['class' => 'card bg-base-100 border border-line']) }}>
    <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
        <h2 class="text-[15px] font-semibold">{{ $title }}</h2>
        <span class="text-[13px] text-muted">What was done by hand</span>
        @if ($link)
            <a href="{{ $link }}" wire:navigate class="btn btn-xs ml-auto">Audit log</a>
        @endif
    </header>
    @if ($entries->isEmpty())
        <p class="px-4 py-6 text-[13px] text-muted">Nothing recorded yet.</p>
    @else
        <ul class="flex flex-col">
            @foreach ($entries as $entry)
                <li class="flex items-start gap-2.5 px-4 py-2.5 border-b border-line last:border-b-0" wire:key="trail-{{ $entry->id }}">
                    <x-icon :name="$entry->action->icon()" class="mt-0.5 text-muted" />
                    <div class="flex flex-col grow min-w-0">
                        <span class="text-[13px]">{{ $entry->action->label() }}{{ $entry->subject ? ' · '.$entry->subject : '' }}</span>
                        <span class="text-xs text-muted">
                            {{ $entry->actor ?? 'deploy.me' }} · {{ $entry->occurred_at->diffForHumans() }}{{ $entry->detail ? ' · '.$entry->detail : '' }}
                        </span>
                    </div>
                    @if ($entry->outcome === AuditOutcome::Failed)
                        <span class="badge badge-sm badge-soft badge-error shrink-0">Failed</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
