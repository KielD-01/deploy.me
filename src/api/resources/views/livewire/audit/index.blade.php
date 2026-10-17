<div class="flex flex-col gap-5">
    <x-page-header title="Audit log" subtitle="Who did what, which object it was about, and how it ended. Never the values." />

    <div class="flex items-center gap-3">
        <div class="join">
            @foreach (['all' => 'Everything', 'by_hand' => 'By hand', 'commands' => 'Commands'] as $key => $label)
                <button type="button" wire:click="$set('kind', @js($key))" @class(['btn btn-sm join-item', 'btn-active' => $kind === $key])>{{ $label }}</button>
            @endforeach
        </div>
        <select wire:model.live="server" class="select select-sm w-52" aria-label="Filter by server">
            <option value="">All servers</option>
            @foreach ($servers as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
        <select wire:model.live="project" class="select select-sm w-52" aria-label="Filter by project">
            <option value="">All projects</option>
            @foreach ($projects as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    </div>

    @if ($entries->isEmpty())
        <x-empty-state icon="shield" title="Nothing recorded yet">
            Trusting a host key, breaking a lock, a GitHub token, something removed, and every command deploy.me runs
            on a server land here. Nothing is ever edited: the table refuses it.
        </x-empty-state>
    @else
        <section class="card bg-base-100 border border-line overflow-x-auto">
            <table class="table">
                <thead><tr><th>When</th><th>Who</th><th>What</th><th>Object</th><th>Outcome</th></tr></thead>
                <tbody>
                    @foreach ($entries as $entry)
                        <tr wire:key="audit-{{ $entry->id }}">
                            <td class="text-[13px] whitespace-nowrap" title="{{ $entry->occurred_at->forViewer()->toDayDateTimeString() }}">{{ $entry->occurred_at->diffForHumans() }}</td>
                            <td class="text-[13px]">{{ $entry->actor ?? 'deploy.me' }}</td>
                            <td>
                                <span class="flex items-center gap-2 text-[13px]">
                                    <x-icon :name="$entry->action->icon()" :size="14" class="text-muted" /> {{ $entry->action->label() }}
                                </span>
                            </td>
                            <td class="text-[13px]">
                                <div class="flex flex-col">
                                    <span class="mono">{{ $entry->subject ?? '—' }}</span>
                                    @if ($entry->detail)
                                        <span class="text-xs text-muted">{{ $entry->detail }}</span>
                                    @endif
                                </div>
                            </td>
                            <td><span class="badge badge-sm {{ $entry->outcome->badgeClass() }}">{{ $entry->outcome->label() }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
        {{ $entries->links() }}
    @endif
</div>
