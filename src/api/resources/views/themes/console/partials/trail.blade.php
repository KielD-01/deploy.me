{{-- What was done by hand, as a pane: the Console theme's <x-audit-trail>. --}}
<section @class(['con-box', $class ?? 'con-wide']) aria-label="Recent activity">
    <span class="con-box-t"><b>history</b> · what was done by hand @if ($link ?? null)<span>·</span><a href="{{ $link }}" wire:navigate>audit log</a>@endif</span>
    @if ($entries->isEmpty())
        <p class="t-mute">Nothing recorded yet.</p>
    @else
        <div class="flex flex-col">
            @foreach ($entries as $entry)
                <div class="flex flex-wrap gap-x-3" wire:key="trail-{{ $entry->id }}">
                    <span class="t-faint w-[9ch] shrink-0" title="{{ $entry->occurred_at->toDayDateTimeString() }}">{{ $entry->occurred_at->diffForHumans(short: true) }}</span>
                    <span class="{{ $entry->outcome === \App\Enums\AuditOutcome::Failed ? 't-err' : '' }}">{{ $entry->action->label() }}{{ $entry->subject ? ' · '.$entry->subject : '' }}{{ $entry->outcome === \App\Enums\AuditOutcome::Failed ? ' · failed' : '' }}</span>
                    <span class="t-mute">{{ $entry->actor ?? 'deploy.me' }}{{ $entry->detail ? ' · '.$entry->detail : '' }}</span>
                </div>
            @endforeach
        </div>
    @endif
</section>
