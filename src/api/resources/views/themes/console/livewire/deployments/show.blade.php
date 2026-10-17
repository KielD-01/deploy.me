{{-- The Console theme's run page: a terminal tailing the run. One line per step that
     folds open on its own log, a segmented progress line, and the page's keys up top. --}}
@php
    use App\Deploy\Delivery\VerdictState;
    use App\Enums\DeploymentKind;
    use App\Enums\DeploymentStatus;
    use App\Enums\StepStatus;
    use Illuminate\Support\Str;

    $status = $deployment->status;
    $release = $deployment->release;
    $tone = match ($status) {
        DeploymentStatus::Succeeded => 't-ok',
        DeploymentStatus::Failed, DeploymentStatus::Stalled => 't-err',
        DeploymentStatus::RolledBack, DeploymentStatus::Cancelled => 't-warn',
        default => 't-run',
    };
    $mark = fn ($step) => $step->key === 'rollback' && $step->status === StepStatus::Succeeded
        ? ['↺', 't-warn']
        : match ($step->status) {
            StepStatus::Succeeded => ['✓', 't-ok'],
            StepStatus::Running => ['›', 't-run'],
            StepStatus::Failed => ['✕', 't-err'],
            StepStatus::Cancelled => ['⊘', 't-warn'],
            StepStatus::Skipped => ['–', 't-faint'],
            default => ['·', 't-faint'],
        };
    $segment = fn ($step) => match ($step->status) {
        StepStatus::Succeeded => 'bg-success',
        StepStatus::Running => 'bg-primary',
        StepStatus::Failed => 'bg-error',
        StepStatus::Cancelled => 'bg-warning',
        StepStatus::Skipped => 'bg-base-300 opacity-50',
        default => 'bg-base-300',
    };
    $done = $steps->filter(fn ($step) => $step->status->isFinished())->count();
    $current = $steps->firstWhere('status', StepStatus::Running) ?? $steps->firstWhere('status', StepStatus::Failed);
    $verdictTone = fn (VerdictState $state) => match ($state) {
        VerdictState::Applicable => 't-ok',
        VerdictState::Pending => 't-run',
        VerdictState::Error => 't-warn',
        default => 't-faint',
    };
    $again = in_array($deployment->kind, [DeploymentKind::Deploy, DeploymentKind::Redeploy], true) && $deployment->commit_sha && $strategy?->value === 'push_archive'
        ? 'deploy '.substr($deployment->commit_sha, 0, 7).' again'
        : 'run it again';
@endphp
<div class="con-page is-fill con-run" x-data="pageKeys" x-on:keydown.window="key($event)">
    <div class="contents" x-data="deployLog({ id: {{ $deployment->id }}, live: @js($deployment->isActive()), steps: @js((object) $steps->pluck('label', 'key')->all()) })">
        <header class="con-prompt">
            <span class="min-w-0 truncate">
                <span class="t-ok">deploy{{ '@'.$server->slug }}</span><span class="t-mute">:</span><span class="t-run">~/runs/{{ $deployment->id }}</span>
                <span class="t-mute">$</span> tail -f deploy.log
            </span>
            <nav class="con-keys" aria-label="Keys">
                @if ($deployment->isActive())
                    @if ($fallback)
                        <button type="button" class="con-key is-go" data-key="n" wire:click="deployNow"><kbd>n</kbd> deploy now with {{ Str::lower($fallback->label()) }}</button>
                    @endif
                    <button type="button" class="con-key is-danger" data-key="c" wire:click="cancel" @disabled($deployment->cancel_requested_at)
                            wire:confirm="Cancel this run? It stops at its next check and gives the folder’s lock back.">
                        <kbd>c</kbd> {{ $deployment->cancel_requested_at ? 'Cancelling…' : 'cancel '.Str::lower($deployment->kind->label()) }}
                    </button>
                @else
                    @if ($resume)
                        <button type="button" class="con-key is-go" data-key="R" wire:click="retryFrom"
                                @if ($resume->switchesBack) wire:confirm="Switch {{ $server->name }} to release {{ $release?->name }} again, and retry from {{ Str::lcfirst($resume->label) }}?" @endif>
                            <kbd>R</kbd> retry from {{ Str::lcfirst($resume->label) }}
                        </button>
                    @endif
                    @if ($canRetry)
                        <button type="button" class="con-key" data-key="r" wire:click="retry"><kbd>r</kbd> {{ $again }}</button>
                    @endif
                    @if ($rollbackTo)
                        <button type="button" class="con-key" data-key="b" wire:click="rollBack"
                                wire:confirm="Switch {{ $server->name }} back to release {{ $rollbackTo->name }}? The database keeps whatever this release migrated.">
                            <kbd>b</kbd> roll back to {{ $rollbackTo->name }}
                        </button>
                    @endif
                    @if ($undoable)
                        <button type="button" class="con-key" data-key="u" wire:click="undoConversion"
                                wire:confirm="Put {{ $target->path }} back the way it was? Every item moves back out of release {{ $undoable->name }}.">
                            <kbd>u</kbd> undo conversion
                        </button>
                    @endif
                @endif
                <label class="con-key con-filter"><kbd>/</kbd><input type="search" x-model.debounce.150ms="query" data-key="/" placeholder="filter" aria-label="Filter the log" x-on:keydown.escape="query = ''; $el.blur()"></label>
                <button type="button" class="con-key" data-key="z" x-on:click="foldAll()"><kbd>z</kbd> fold all</button>
                <button type="button" class="con-key" data-key="f" x-on:click="end()" x-bind:class="{ 'is-on': follow }"><kbd>f</kbd> follow</button>
                <a href="{{ route('deployments.log', $deployment) }}" class="con-key" data-key="d"><kbd>d</kbd> download</a>
            </nav>
        </header>

        <div class="flex flex-col gap-2 px-[14px] pt-3 pb-2">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h1 class="t-b text-[15px]">
                    {{ $deployment->number() }}
                    <a href="{{ route('projects.show', $project) }}" wire:navigate class="hover:underline">{{ $project->name }}</a>
                    <span class="t-mute font-normal">→</span>
                    <a href="{{ route('servers.show', $server) }}" wire:navigate class="hover:underline">{{ $server->name }}</a>
                </h1>
                <span class="{{ $tone }}">● {{ $status->label() }}@if ($deployment->started_at) {{ $deployment->durationLabel() }}@endif</span>
                <span class="t-faint min-w-0">
                    @if ($retryOf)
                        Retry of <a href="{{ route('deployments.show', $retryOf) }}" wire:navigate class="t-run hover:underline">{{ $retryOf->number() }}</a> from {{ Str::lcfirst((string) $resumeLabel) }}
                    @else
                        {{ Str::lower($deployment->kind->label()) }}
                    @endif
                    @if ($deployment->commit_sha) · {{ substr($deployment->commit_sha, 0, 7) }} “{{ Str::limit((string) $deployment->commit_message, 60) }}”@endif
                    @if ($release && $release->inReleasesFolder()) · release {{ $release->name }}@endif
                    · {{ $deployment->started_at ? 'started '.$deployment->started_at->diffForHumans() : 'queued '.$deployment->created_at->diffForHumans() }}{{ $deployment->user_id ? ' by you' : '' }}
                    @if ($retriedAs) · Retried as <a href="{{ route('deployments.show', $retriedAs) }}" wire:navigate class="t-run hover:underline">{{ $retriedAs->number() }}</a>@endif
                </span>
            </div>
            <div class="flex flex-wrap gap-x-4 t-mute">
                @if ($strategy)<span>strategy <span class="t-b font-normal">{{ Str::lower($strategy->label()) }}</span></span>@endif
                <span>mode <span class="t-b font-normal">{{ $project->versionable ? 'releases' : 'in place' }}{{ $project->dockerized ? ' + docker' : '' }}</span></span>
                @if ($project->dockerized)<span>stack <span class="t-b font-normal">{{ $project->stack_name }}</span></span>@endif
                <span>path <span class="t-b font-normal">{{ $target->path }}</span></span>
            </div>
            @if ($steps->isNotEmpty())
                <div class="con-progress" role="img" aria-label="Progress: {{ $done }} of {{ $steps->count() }} steps{{ $current ? ', '.Str::lcfirst($current->label) : '' }}">
                    @foreach ($steps as $step)
                        <span wire:key="segment-{{ $step->id }}" title="{{ $step->label }}" class="{{ $segment($step) }}" style="flex: {{ max(1, min(12, (int) ceil(($step->durationSeconds() ?? 0) / 10))) }}"></span>
                    @endforeach
                </div>
            @endif
        </div>

        <div x-ref="pane" x-on:scroll="follow = atBottom()" class="con-term" aria-label="Log">
            @if ($problem)
                <div role="alert" class="con-line t-err">✕ {{ $problem }}</div>
            @endif
            @if ($notice)
                <div role="status" class="con-line t-ok">✓ {{ $notice }}</div>
            @endif
            @if (! $deployment->isActive() && $deployment->outcome)
                <div role="alert" class="con-line {{ $tone }}">{{ $status === DeploymentStatus::Succeeded ? '✓' : '✕' }} {{ $deployment->outcome }}</div>
            @endif
            @if ($archived)
                <div role="note" class="con-line t-mute">ⓘ This run’s log was archived. <a href="{{ route('deployments.log', $deployment) }}" class="t-run hover:underline">Download it</a> to read it.</div>
            @endif

            @if ($waiting)
                <div class="con-line t-run">⧗ waiting for CI · checked again every {{ \App\Deploy\Pipeline\PipelineRunner::RECHECK_EVERY }} s · last check {{ $deployment->heartbeat_at?->diffForHumans() ?? 'not yet' }}</div>
                @foreach ($deployment->strategy_trace ?? [] as $i => $entry)
                    @php $state = VerdictState::tryFrom($entry['state'] ?? '') ?? VerdictState::NotApplicable; @endphp
                    <div class="con-line pl-[30px]">
                        <span class="{{ $verdictTone($state) }}">{{ $i + 1 }} · {{ $entry['label'] ?? $entry['key'] }} {{ $state->sentence() }}</span>
                        @unless ($state->quiet())<span class="t-mute">{{ $entry['reason'] ?? '' }}</span>@endunless
                    </div>
                @endforeach
                @if ($waiting['run'])
                    <div class="con-line pl-[30px]">
                        <span class="t-b">{{ $waiting['run']['workflow'] ?: ($waiting['run']['name'] ?? 'CI') }} · run #{{ $waiting['run']['number'] }}</span>
                        @if ($waiting['run']['url'] ?? null)<a href="{{ $waiting['run']['url'] }}" target="_blank" rel="noopener" class="t-run hover:underline">open on GitHub ↗</a>@endif
                    </div>
                    @forelse ($waiting['jobs'] as $job)
                        @php
                            $jobDone = ($job['status'] ?? null) === 'completed';
                            $ok = ($job['conclusion'] ?? null) === 'success';
                            $from = $job['started_at'] ? \Illuminate\Support\Carbon::parse($job['started_at']) : null;
                            $took = $from ? (int) $from->diffInSeconds($job['completed_at'] ? \Illuminate\Support\Carbon::parse($job['completed_at']) : now(), true) : null;
                        @endphp
                        <div class="con-line pl-[44px]">
                            <span class="{{ ! $from ? 't-faint' : (! $jobDone ? 't-run' : ($ok ? 't-ok' : 't-err')) }}">{{ ! $from ? '·' : (! $jobDone ? '›' : ($ok ? '✓' : '✕')) }} {{ $job['name'] }}</span>
                            @if ($took !== null)<span class="t-mute">{{ intdiv($took, 60) }}m {{ str_pad((string) ($took % 60), 2, '0', STR_PAD_LEFT) }}s</span>@endif
                        </div>
                    @empty
                        <div class="con-line pl-[44px] t-mute">GitHub hasn’t listed its jobs yet.</div>
                    @endforelse
                @endif
            @elseif ($deployment->strategy_trace)
                <details class="con-fold" wire:key="delivery" wire:ignore.self>
                    <summary class="t-mute font-normal">
                        <span class="con-caret"></span>
                        <span class="con-fill">why {{ $strategy ? Str::lower($strategy->label()) : 'this delivery' }} · first that applies wins</span>
                    </summary>
                    @foreach ($deployment->strategy_trace as $i => $entry)
                        @php $state = VerdictState::tryFrom($entry['state'] ?? '') ?? VerdictState::NotApplicable; @endphp
                        <div class="con-line pl-[30px]">
                            <span class="{{ $verdictTone($state) }}">{{ $i + 1 }} · {{ $entry['label'] ?? $entry['key'] }} {{ $state->sentence() }}</span>
                            <span class="t-mute">{{ $entry['reason'] ?? '' }}</span>
                        </div>
                    @endforeach
                </details>
            @endif

            {{-- Lines of no step, and of a step whose line isn't here yet. --}}
            <div x-ref="lines" wire:ignore></div>

            @forelse ($steps as $step)
                @php
                    [$glyph, $stepTone] = $mark($step);
                    $quiet = in_array($step->status, [StepStatus::Pending, StepStatus::Skipped], true);
                    $open = in_array($step->status, [StepStatus::Running, StepStatus::Failed], true);
                @endphp
                {{-- The fold keeps whatever open state it has; its summary says what the step would like. --}}
                <details class="con-fold" wire:key="step-{{ $step->id }}" wire:ignore.self data-fold="{{ $step->key }}"@if ($open) open @endif>
                    <summary data-open="{{ $open ? '1' : '0' }}" x-on:click="touch(@js($step->key))"
                             @class([$quiet ? 't-faint' : $stepTone, 'is-running' => $step->status === StepStatus::Running, 'is-failed' => $step->status === StepStatus::Failed, 'is-quiet' => $quiet])>
                        <span class="con-caret"></span>
                        <span>{{ $glyph }}</span>
                        <span class="con-fill">{{ $step->label }}@if ($step->detail)<span @class(['font-normal', 't-err' => $step->status === StepStatus::Failed, 't-mute' => $step->status !== StepStatus::Failed])> · {{ $step->detail }}</span>@endif</span>
                        <span class="t-mute font-normal tabular-nums">{{ $step->durationLabel() }}</span>
                    </summary>
                    <div data-log-step="{{ $step->key }}" wire:ignore></div>
                </details>
            @empty
                <div class="con-line t-mute">{{ $deployment->isActive() ? 'Waiting for a deploy worker to pick it up…' : 'It never started.' }}</div>
            @endforelse

            @if ($steps->isNotEmpty())
                <p x-show="count === 0" class="con-line t-faint">No output yet.</p>
            @endif

            @if ($backup)
                <div class="con-line mt-2">
                    <span class="t-warn">backup</span>
                    <span class="break-all">{{ $backup }}</span>
                    <button type="button" class="con-act is-danger" wire:click="removeBackup"
                            wire:confirm="Remove {{ $backup }} on {{ $server->name }}? The converted files stay as they are.">remove backup</button>
                </div>
                <div class="con-line pl-[30px] t-mute">The old layout as hard links, so it takes almost no space. Undo doesn’t need it. Remove it once you’re happy with the new layout.</div>
            @endif
        </div>
    </div>
</div>
