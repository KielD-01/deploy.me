<div class="flex flex-col gap-5">
    @php
        use App\Deploy\Delivery\VerdictState;
        use App\Enums\DeploymentKind;
        use App\Enums\DeploymentStatus;
        use App\Enums\StepStatus;
        $status = $deployment->status;
        $done = $steps->filter(fn ($step) => $step->status->isFinished())->count();
        $release = $deployment->release;
    @endphp

    <div class="flex flex-col gap-2">
        <div class="flex items-center gap-1.5 text-[13px] text-muted">
            <a href="{{ route('deployments.index') }}" wire:navigate class="hover:text-base-content">Deployments</a>
            <x-icon name="chevron-right" :size="12" /> <span>{{ $deployment->number() }}</span>
        </div>
        <div class="flex items-end gap-4">
            <div class="flex flex-col gap-1 grow min-w-0">
                <div class="flex items-center gap-2.5">
                    <h1 class="text-[22px] leading-7 font-semibold tracking-tight">
                        {{ $deployment->number() }}
                        <a href="{{ route('projects.show', $project) }}" wire:navigate class="hover:underline">{{ $project->name }}</a>
                        <span class="text-muted font-normal">→</span>
                        <a href="{{ route('servers.show', $server) }}" wire:navigate class="hover:underline">{{ $server->name }}</a>
                    </h1>
                    <span class="badge gap-1 {{ $status->badgeClass() }}">
                        <x-icon :name="$status->icon()" :size="12" @class(['animate-spin' => $status === DeploymentStatus::Running]) /> {{ $status->label() }}
                    </span>
                </div>
                <p class="text-muted">
                    @if ($retryOf)
                        Retry of <a href="{{ route('deployments.show', $retryOf) }}" wire:navigate class="link link-hover">{{ $retryOf->number() }}</a> from {{ Str::lcfirst((string) $resumeLabel) }}
                    @else
                        {{ $deployment->kind->label() }}
                    @endif
                    @if ($release && $release->inReleasesFolder())
                        · Release <span class="mono">{{ $release->name }}</span>
                    @endif
                    @if ($deployment->commit_sha)
                        · <span class="mono">{{ substr($deployment->commit_sha, 0, 7) }}</span> {{ $deployment->commit_message }}
                    @endif
                    · {{ $deployment->started_at ? 'started '.$deployment->started_at->diffForHumans() : 'queued '.$deployment->created_at->diffForHumans() }}
                    {{ $deployment->user_id ? 'by you' : '' }}
                    @if ($deployment->finished_at && $deployment->started_at) · took {{ $deployment->durationLabel() }} @endif
                    @if ($retriedAs) · Retried as <a href="{{ route('deployments.show', $retriedAs) }}" wire:navigate class="link link-hover">{{ $retriedAs->number() }}</a> @endif
                </p>
            </div>
            <div class="flex items-center gap-2">
                @if ($deployment->isActive())
                    @if ($fallback)
                        <button type="button" class="btn btn-sm" wire:click="deployNow">
                            <x-icon name="deploy" :size="14" /> Deploy now with {{ $fallback->label() }}
                        </button>
                    @endif
                    <button type="button" class="btn btn-sm btn-outline btn-error" wire:click="cancel" @disabled($deployment->cancel_requested_at)
                            wire:confirm="Cancel this run? It stops at its next check and gives the folder’s lock back.">
                        <x-icon name="stop" :size="14" /> {{ $deployment->cancel_requested_at ? 'Cancelling…' : 'Cancel '.Str::lower($deployment->kind->label()) }}
                    </button>
                @else
                    @if ($undoable)
                        <button type="button" class="btn btn-sm" wire:click="undoConversion"
                                wire:confirm="Put {{ $target->path }} back the way it was? Every item moves back out of release {{ $undoable->name }}.">
                            <x-icon name="rollback" :size="14" /> Undo conversion
                        </button>
                    @endif
                    @if ($rollbackTo)
                        <button type="button" class="btn btn-sm" wire:click="rollBack"
                                wire:confirm="Switch {{ $server->name }} back to release {{ $rollbackTo->name }}? The database keeps whatever this release migrated.">
                            <x-icon name="rollback" :size="14" /> Roll back to {{ $rollbackTo->name }}
                        </button>
                    @endif
                    @if ($resume)
                        <button type="button" class="btn btn-sm btn-primary" wire:click="retryFrom"
                                @if ($resume->switchesBack) wire:confirm="Switch {{ $server->name }} to release {{ $release?->name }} again, and retry from {{ Str::lcfirst($resume->label) }}?" @endif>
                            <x-icon name="refresh" :size="14" /> Retry from {{ Str::lcfirst($resume->label) }}
                        </button>
                    @endif
                    @if ($canRetry)
                        <button type="button" class="btn btn-sm" wire:click="retry">
                            <x-icon name="refresh" :size="14" />
                            {{ in_array($deployment->kind, [DeploymentKind::Deploy, DeploymentKind::Redeploy], true) && $deployment->commit_sha && $strategy?->value === 'push_archive'
                                ? 'Deploy '.substr($deployment->commit_sha, 0, 7).' again'
                                : 'Run it again' }}
                        </button>
                    @endif
                @endif
            </div>
        </div>
    </div>

    @if ($problem)
        <div role="alert" class="alert alert-soft alert-error text-[13px]"><x-icon name="x-circle" /> {{ $problem }}</div>
    @endif
    @if ($notice)
        <div role="status" class="alert alert-soft alert-success text-[13px]"><x-icon name="check-circle" /> {{ $notice }}</div>
    @endif

    <div class="flex flex-wrap items-center gap-2 text-[13px]">
        @if ($strategy)
            <span class="badge badge-ghost h-7 gap-1.5 px-3"><x-icon name="tag" :size="12" class="text-muted" /> Strategy <span class="font-semibold">{{ $strategy->label() }}</span></span>
        @endif
        <span class="badge badge-ghost h-7 gap-1.5 px-3"><x-icon name="layers" :size="12" class="text-muted" /> Mode <span class="font-semibold">{{ $project->versionable ? 'Releases' : 'In place' }}{{ $project->dockerized ? ' + Docker' : '' }}</span></span>
        @if ($project->dockerized)
            <span class="badge badge-ghost h-7 gap-1.5 px-3"><x-icon name="box" :size="12" class="text-muted" /> Stack <span class="font-semibold">{{ $project->stack_name }}</span></span>
        @endif
        <span class="badge badge-ghost h-7 gap-1.5 px-3"><x-icon name="folder" :size="12" class="text-muted" /> Path <span class="font-mono text-[12px]">{{ $target->path }}</span></span>
    </div>

    @if ($archived)
        <div role="note" class="alert alert-soft alert-info text-[13px]">
            <x-icon name="info" />
            <span>This run’s log was archived. <a href="{{ route('deployments.log', $deployment) }}" class="link">Download it</a> to read it.</span>
        </div>
    @endif

    @if ($waiting)
        <div class="grid grid-cols-2 gap-4 items-start">
            <section class="card bg-base-100 border border-line">
                <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                    <h2 class="text-[15px] font-semibold">Why it’s waiting</h2>
                </header>
                <ol class="flex flex-col">
                    @foreach ($deployment->strategy_trace ?? [] as $i => $entry)
                        @php $state = VerdictState::tryFrom($entry['state'] ?? '') ?? VerdictState::NotApplicable; @endphp
                        <li @class(['flex items-start gap-2.5 px-4 py-3 border-b border-line', 'bg-info/5' => $state === VerdictState::Pending, 'bg-warning/5' => $state === VerdictState::Error])>
                            <x-icon :name="$state->icon()" @class(['mt-0.5 shrink-0', $state->toneClass()]) />
                            <div class="flex flex-col min-w-0">
                                <span @class(['text-[13px] font-semibold', 'text-muted' => $state->quiet()])>
                                    {{ $i + 1 }} · {{ $entry['label'] ?? $entry['key'] }} {{ $state->sentence() }}
                                </span>
                                @unless ($state->quiet())
                                    <span class="text-xs text-muted">{{ $entry['reason'] ?? '' }}</span>
                                @endunless
                            </div>
                        </li>
                    @endforeach
                </ol>
                <p class="px-4 py-3 text-xs text-muted">
                    Checked again every {{ \App\Deploy\Pipeline\PipelineRunner::RECHECK_EVERY }} s · last check {{ $deployment->heartbeat_at?->diffForHumans() ?? 'not yet' }}
                </p>
            </section>

            @if ($waiting['run'])
                <section class="card bg-base-100 border border-line">
                    <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                        <h2 class="text-[15px] font-semibold">{{ $waiting['run']['workflow'] ?: ($waiting['run']['name'] ?? 'CI') }} · run #{{ $waiting['run']['number'] }}</h2>
                        @if ($waiting['run']['url'] ?? null)
                            <a href="{{ $waiting['run']['url'] }}" target="_blank" rel="noopener" class="btn btn-xs ml-auto"><x-icon name="external" :size="12" /> Open on GitHub</a>
                        @endif
                    </header>
                    <ul class="flex flex-col">
                        @forelse ($waiting['jobs'] as $job)
                            @php
                                $done = ($job['status'] ?? null) === 'completed';
                                $ok = ($job['conclusion'] ?? null) === 'success';
                                $from = $job['started_at'] ? \Illuminate\Support\Carbon::parse($job['started_at']) : null;
                                $to = $job['completed_at'] ? \Illuminate\Support\Carbon::parse($job['completed_at']) : now();
                            @endphp
                            <li class="flex items-center gap-2.5 px-4 py-2.5 border-b border-line last:border-b-0">
                                <x-icon :name="! $from ? 'pending' : (! $done ? 'loader' : ($ok ? 'check-circle' : 'x-circle'))"
                                        @class(['shrink-0', 'text-muted' => ! $from, 'text-info animate-spin' => $from && ! $done, 'text-success' => $done && $ok, 'text-error' => $done && ! $ok]) />
                                <span @class(['grow text-[13px]', 'text-muted' => ! $from])>{{ $job['name'] }}</span>
                                @if ($from)
                                    <span class="text-xs text-muted tabular-nums">{{ intdiv((int) $from->diffInSeconds($to, true), 60) }}m {{ str_pad((string) ((int) $from->diffInSeconds($to, true) % 60), 2, '0', STR_PAD_LEFT) }}s</span>
                                @endif
                            </li>
                        @empty
                            <li class="px-4 py-4 text-[13px] text-muted">GitHub hasn’t listed its jobs yet.</li>
                        @endforelse
                    </ul>
                </section>
            @endif
        </div>
    @endif

    @if (! $deployment->isActive() && $deployment->outcome)
        <div role="alert" @class([
            'alert alert-soft text-[13px]',
            'alert-success' => $status === DeploymentStatus::Succeeded,
            'alert-error' => in_array($status, [DeploymentStatus::Failed, DeploymentStatus::Stalled, DeploymentStatus::RolledBack], true),
        ])>
            <x-icon :name="$status === DeploymentStatus::RolledBack ? 'x-circle' : $status->icon()" /> {{ $deployment->outcome }}
        </div>
    @endif

    <div class="grid grid-cols-[340px_minmax(0,1fr)] gap-4 items-start">
        <div class="flex flex-col gap-4">
            <section class="card bg-base-100 border border-line">
                <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                    <h2 class="text-[15px] font-semibold">Steps</h2>
                    @if ($steps->isNotEmpty())
                        <span class="text-[13px] text-muted">{{ $done }} of {{ $steps->count() }} done</span>
                    @endif
                </header>
                <ol class="flex flex-col">
                    @forelse ($steps as $step)
                        @php $switchedBack = $step->key === 'rollback' && $step->status === StepStatus::Succeeded; @endphp
                        <li wire:key="step-{{ $step->id }}" @class([
                            'flex items-start gap-2.5 px-4 py-2.5 border-b border-line last:border-b-0',
                            'bg-info/5' => $step->status === StepStatus::Running,
                            'bg-error/5' => $step->status === StepStatus::Failed,
                        ])>
                            <x-icon :name="$switchedBack ? 'rollback' : $step->status->icon()"
                                    @class([$switchedBack ? 'text-warning' : $step->status->textClass(), 'mt-0.5', 'animate-spin' => $step->status === StepStatus::Running]) />
                            <div class="flex flex-col grow min-w-0">
                                <span @class([
                                    'font-medium',
                                    'font-semibold' => in_array($step->status, [StepStatus::Running, StepStatus::Failed], true),
                                    'text-muted' => in_array($step->status, [StepStatus::Pending, StepStatus::Skipped], true),
                                ])>{{ $step->label }}</span>
                                @if ($step->detail)
                                    <span @class(['text-xs break-words', 'text-error' => $step->status === StepStatus::Failed, 'text-muted' => $step->status !== StepStatus::Failed])>{{ $step->detail }}</span>
                                @endif
                            </div>
                            <span class="text-xs text-muted tabular-nums">{{ $step->durationLabel() }}</span>
                        </li>
                    @empty
                        <li class="px-4 py-4 text-[13px] text-muted">
                            {{ $deployment->isActive() ? 'Waiting for a deploy worker to pick it up…' : 'It never started.' }}
                        </li>
                    @endforelse
                </ol>
            </section>

            @if ($backup)
                <section class="card bg-base-100 border border-line">
                    <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                        <h2 class="text-[15px] font-semibold">Backup</h2>
                    </header>
                    <div class="p-4 flex flex-col gap-2.5">
                        <span class="mono text-[13px] break-all">{{ $backup }}</span>
                        <span class="text-xs text-muted">The old layout as hard links, so it takes almost no space. Undo doesn’t need it. Remove it once you’re happy with the new layout.</span>
                        <button type="button" class="btn btn-xs btn-outline btn-error self-start" wire:click="removeBackup"
                                wire:confirm="Remove {{ $backup }} on {{ $server->name }}? The converted files stay as they are.">
                            <x-icon name="trash" :size="12" /> Remove backup
                        </button>
                    </div>
                </section>
            @endif

            @if ($deployment->strategy_trace)
                <section class="card bg-base-100 border border-line">
                    <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                        <h2 class="text-[15px] font-semibold">Delivery</h2>
                        <span class="text-[13px] text-muted">first that applies wins</span>
                    </header>
                    <ol class="flex flex-col">
                        @foreach ($deployment->strategy_trace as $i => $entry)
                            @php $state = VerdictState::tryFrom($entry['state'] ?? '') ?? VerdictState::NotApplicable; @endphp
                            <li class="flex items-start gap-2.5 px-4 py-2.5 border-b border-line last:border-b-0">
                                <x-icon :name="$state->icon()" @class(['mt-0.5 shrink-0', $state->toneClass()]) />
                                <div class="flex flex-col min-w-0">
                                    <span @class(['text-[13px]', 'font-semibold' => $state === VerdictState::Applicable, 'text-muted' => $state !== VerdictState::Applicable])>{{ $i + 1 }} · {{ $entry['label'] ?? $entry['key'] }} {{ $state->sentence() }}</span>
                                    <span class="text-xs text-muted">{{ $entry['reason'] ?? '' }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endif
        </div>

        <section class="card bg-base-100 border border-line overflow-hidden" wire:ignore
                 x-data="deployLog({ id: {{ $deployment->id }}, live: @js($deployment->isActive()), steps: @js((object) $steps->pluck('label', 'key')->all()) })">
            <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                <h2 class="text-[15px] font-semibold">Log</h2>
                <span class="text-[13px] text-muted"><span x-show="live" class="text-info">streaming live · </span><span x-text="count">0</span> lines</span>
                <select x-model="step" class="select select-sm ml-auto w-48" aria-label="Show one step">
                    <option value="">All steps</option>
                    <template x-for="[key, label] in Object.entries(steps)" :key="key">
                        <option :value="key" x-text="label"></option>
                    </template>
                </select>
                <a href="{{ route('deployments.log', $deployment) }}" class="btn btn-sm"><x-icon name="file" :size="14" /> Download</a>
            </header>
            <div x-ref="pane" x-on:scroll="follow = atBottom()" class="bg-log text-log-content font-mono text-[12px] leading-5 h-[560px] overflow-y-auto px-4 py-3">
                <div x-ref="lines"></div>
                <p x-show="count === 0" class="text-log-content/60">No output yet.</p>
            </div>
        </section>
    </div>
</div>
