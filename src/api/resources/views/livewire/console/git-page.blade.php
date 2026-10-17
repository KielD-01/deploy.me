{{-- The Console theme's project page, main pane: the branch as `git log --graph`. --}}
@php
    $ci = $project?->ci_state;
    $ciSha = $project?->ci_run['sha'] ?? null;
    $ciMark = match ($ci) {
        \App\Enums\CiState::Passing => ['✓', 't-ok'],
        \App\Enums\CiState::Failing => ['✕', 't-err'],
        \App\Enums\CiState::Running => ['●', 't-run'],
        default => null,
    };
@endphp
<div class="flex flex-col py-1">
    @if ($project === null)
        <p class="px-3 t-mute">This project is gone.</p>
    @else
        @forelse ($commits as $commit)
            @php
                $commitTags = $tags[$commit['sha']] ?? [];
                $live = collect($commitTags)->contains(fn ($tag) => $tag[1] === 't-ok');
                $running = collect($commitTags)->contains(fn ($tag) => $tag[1] === 't-run');
                $node = $running ? 't-run' : ($live ? 't-ok' : ($commitTags !== [] ? 't-v' : 't-b'));
                $mark = $ciSha !== null && str_starts_with($commit['sha'], (string) $ciSha) ? $ciMark : null;
            @endphp
            <div @class(['con-commit', 'is-sel' => $loop->first]) wire:key="commit-{{ $commit['sha'] }}">
                <span class="con-node {{ $node }}">●</span>
                <span class="w-[1.5ch] shrink-0 {{ $mark[1] ?? 't-faint' }}" title="{{ $mark ? 'CI on this commit: '.Str::lower($ci->label()) : '' }}">{{ $mark[0] ?? ' ' }}</span>
                <span class="shrink-0 t-mute">{{ substr($commit['sha'], 0, 7) }}</span>
                <span class="flex-1 min-w-0 flex flex-col">
                    <span>
                        @foreach ($commitTags as [$label, $tone])<span class="t-tag {{ $tone }} mr-1 whitespace-nowrap">{{ $label }}</span>@endforeach
                        <span class="break-words">{{ $commit['subject'] }}</span>
                        @if ($commit['migration'])<span class="t-warn"> +migration</span>@endif
                    </span>
                </span>
                @if ($targets->isNotEmpty())
                    <button type="button" class="con-act shrink-0" wire:click="$dispatch('open-deploy-dialog', { commit: @js($commit['sha']) })"
                            title="Deploy {{ substr($commit['sha'], 0, 7) }}">deploy</button>
                @endif
                <span class="w-[6ch] shrink-0 text-right t-faint" title="{{ $commit['date']?->toDayDateTimeString() }}">{{ $commit['date']?->diffForHumans(short: true, syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) ?? '' }}</span>
            </div>
        @empty
            <p class="px-3 t-mute">deploy.me can't read this branch yet: its repository isn't on the Mac and has no mirror.</p>
        @endforelse
        @if ($commits !== [])
            <div class="con-commit"><span class="con-node t-faint">┆</span></div>
        @endif
    @endif
</div>
