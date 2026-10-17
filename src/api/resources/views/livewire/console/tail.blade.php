<div class="h-full flex flex-col">
    @if ($run === null)
        <div class="term t-mute">No runs yet. Deploy something from the shell: deploy</div>
    @else
        <div class="px-3 pt-2 pb-1 flex flex-col gap-1.5">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
                <a href="{{ route('deployments.show', $run) }}" wire:navigate class="t-b">#{{ $run->id }}</a>
                <span>{{ $run->kind->value }} {{ $run->project?->slug }} → {{ $run->server?->slug }}</span>
                <span class="{{ $run->isActive() ? 't-run' : ($run->status === \App\Enums\DeploymentStatus::Succeeded ? 't-ok' : 't-err') }}">● {{ strtolower($run->status->label()) }}{{ $run->started_at ? ' '.$run->durationLabel() : '' }}</span>
                <span class="t-faint">{{ $run->commit_sha ? substr($run->commit_sha, 0, 7) : '' }} {{ \Illuminate\Support\Str::limit((string) $run->commit_message, 40) }}</span>
            </div>
            @if ($segments !== [])
                <div class="flex gap-[3px]" aria-label="Steps">
                    @foreach ($segments as $segment)
                        <span title="{{ $segment['name'] }}" class="h-[5px] flex-1 {{ $segment['tone'] }}"></span>
                    @endforeach
                </div>
                @if ($current)<span class="t-mute text-[11px]">{{ strtolower($current->label) }}{{ $current->detail ? ' · '.\Illuminate\Support\Str::limit($current->detail, 60) : '' }}</span>@endif
            @endif
        </div>
        <div class="flex-1 min-h-0 flex flex-col" wire:key="log-{{ $run->id }}" x-data="deployLog({ id: {{ $run->id }}, live: @js($run->isActive()), active: @js(\App\Enums\DeploymentStatus::ACTIVE), keep: {{ \App\Models\Deployment::LOG_PANE_LINES }}, page: {{ \App\Models\Deployment::LOG_PAGE }}, steps: {} })">
            <div x-ref="pane" x-on:scroll="follow = atBottom()" class="flex-1 min-h-0 overflow-y-auto px-3 py-1 text-[12px] leading-5">
                <div x-ref="lines" wire:ignore></div>
            </div>
        </div>
    @endif
</div>
