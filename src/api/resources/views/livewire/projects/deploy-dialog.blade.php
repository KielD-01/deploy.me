<div>
    @php use App\Deploy\Delivery\VerdictState; @endphp

    @if ($open)
        @php
            $project = $this->project;
            $selected = $commit !== '' ? $commit : ($commits[0]['sha'] ?? $resolution?->verdict?->commit ?? $resolution?->pending?->commit);
            $picked = $targets->whereIn('id', $targetIds);
            $where = $picked->count() > 1 ? $picked->count().' targets' : ($picked->first()?->server->name ?? 'a target');
            // A run whose first strategy is still waiting for CI can start too:
            // it holds nothing on the server until CI is done.
            $willWait = $resolution?->waiting() ?? false;
            $canDeploy = $picked->isNotEmpty() && (($resolution?->found() ?? false) || $willWait);
        @endphp
        <div class="modal modal-open items-start" role="dialog" aria-label="Deploy {{ $project->name }}">
            {{-- The box stops a margin short of the window and only its middle scrolls, so the
                 header and the Deploy button stay on screen however long the commits and the
                 delivery trace get. The modal clips what overflows it: a box taller than the
                 window would put its own footer out of reach. --}}
            <div class="modal-box max-w-[720px] p-0 mt-16 max-h-[calc(100dvh-8rem)] flex flex-col overflow-hidden">
                <header class="flex items-center gap-2.5 min-h-[56px] px-5 border-b border-line shrink-0">
                    <h2 class="text-[17px] font-semibold">Deploy {{ $project->name }}</h2>
                    <button type="button" class="btn btn-sm btn-ghost btn-square ml-auto" wire:click="close" aria-label="Close"><x-icon name="x" /></button>
                </header>

                <div class="p-5 flex flex-col gap-5 min-h-0 overflow-y-auto">
                    @if ($problem)
                        <div role="alert" class="alert alert-soft alert-error text-[13px]"><x-icon name="x-circle" /> {{ $problem }}</div>
                    @endif

                    <div class="flex flex-col gap-2">
                        <span class="text-[13px] font-medium">Target</span>
                        <div class="join">
                            @foreach ($targets as $target)
                                <button type="button" wire:click="pickTarget('{{ $target->id }}')" wire:key="target-{{ $target->id }}"
                                        @class(['btn btn-sm join-item', 'btn-active' => $targetIds === [$target->id]])>{{ $target->server->name }}</button>
                            @endforeach
                            @if ($targets->count() > 1)
                                <button type="button" wire:click="pickTarget('all')" @class(['btn btn-sm join-item', 'btn-active' => count($targetIds) > 1])>
                                    {{ $targets->count() === 2 ? 'Both' : 'All '.$targets->count() }}
                                </button>
                            @endif
                        </div>
                    </div>

                    <div class="flex flex-col gap-2">
                        <div class="flex items-center gap-2">
                            <span class="text-[13px] font-medium">{{ $commits === [] && $project->local_path ? 'What to deploy' : 'Commit on '.$project->branch }}</span>
                            @if ($since !== null)
                                <span class="text-[13px] text-muted ml-auto">
                                    @if ($since === 0)
                                        The live release is the latest commit
                                    @else
                                        {{ $since }} {{ Str::plural('commit', $since) }} since the live release{{ $sinceMigrations ? ' · '.$sinceMigrations.' '.($sinceMigrations === 1 ? 'adds' : 'add').' a migration' : '' }}
                                    @endif
                                </span>
                            @endif
                        </div>

                        @if ($commits === [])
                            <div class="rounded-box border border-line px-4 py-3 text-[13px]">
                                {{ $project->local_path ? 'The folder as it is now' : 'The latest commit on '.$project->branch }}
                            </div>
                        @else
                            <ul class="rounded-box border border-line max-h-[264px] overflow-y-auto">
                                @foreach ($commits as $i => $row)
                                    @php $isSelected = $commit === '' ? $i === 0 : $commit === $row['sha']; @endphp
                                    <li wire:key="commit-{{ $row['sha'] }}">
                                        <label @class(['flex items-center gap-3 px-4 py-2.5 border-b border-line cursor-pointer', 'bg-primary/5' => $isSelected])>
                                            <input type="radio" class="radio radio-sm radio-primary" name="deploy-commit" @checked($isSelected)
                                                   wire:click="pickCommit('{{ $i === 0 ? '' : $row['sha'] }}')">
                                            <span class="mono text-[13px]">{{ substr($row['sha'], 0, 7) }}</span>
                                            <span class="grow truncate text-[13px]">{{ $row['subject'] }}</span>
                                            @if ($row['migration'])
                                                <span class="badge badge-sm badge-soft badge-warning gap-1 shrink-0"><x-icon name="database" :size="12" /> Adds a migration</span>
                                            @endif
                                            @foreach ($live[$row['sha']] ?? [] as $server)
                                                <span class="badge badge-sm badge-soft badge-success shrink-0">Live on {{ $server }}</span>
                                            @endforeach
                                            @if ($state = $ci[$row['sha']] ?? null)
                                                @if ($state === \App\Enums\CiState::Running)
                                                    <span class="badge badge-sm badge-soft badge-info gap-1 shrink-0"><x-icon name="loader" :size="12" class="animate-spin" /> CI running</span>
                                                @else
                                                    <x-icon :name="$state->icon()" :size="14" @class(['shrink-0', 'text-success' => $state === \App\Enums\CiState::Passing, 'text-error' => $state === \App\Enums\CiState::Failing, 'text-muted' => ! in_array($state, [\App\Enums\CiState::Passing, \App\Enums\CiState::Failing], true)]) title="CI {{ Str::lower($state->label()) }}" />
                                                @endif
                                            @endif
                                            <span class="text-xs text-muted shrink-0 w-24 text-right">{{ $row['date']?->diffForHumans() }}</span>
                                        </label>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    @if ($resolution)
                        <div class="flex flex-col gap-2">
                            <span class="text-[13px] font-medium">Delivery</span>
                            <ul class="rounded-box border border-line">
                                @foreach ($resolution->trace as $entry)
                                    @php $state = VerdictState::from($entry['state']); @endphp
                                    <li class="flex items-start gap-3 px-4 py-2.5 border-b border-line last:border-b-0" wire:key="trace-{{ $entry['key'] }}">
                                        <x-icon :name="$state->icon()"
                                                @class([
                                                    'mt-0.5 shrink-0',
                                                    $state->toneClass(),
                                                    'text-error' => $state === VerdictState::NotApplicable && ! $resolution->found(),
                                                ]) />
                                        <div class="flex flex-col grow min-w-0">
                                            <span @class(['text-[13px]', 'font-semibold' => $state === VerdictState::Applicable, 'text-muted' => $state !== VerdictState::Applicable])>
                                                {{ $entry['label'] }}{{ $state === VerdictState::Off ? ' · off for this project' : ($state === VerdictState::NotNeeded ? ' · not needed' : '') }}
                                            </span>
                                            @unless ($state->quiet())
                                                <span class="text-xs text-muted">{{ $entry['reason'] }}</span>
                                            @endunless
                                        </div>
                                        @if ($state === VerdictState::Applicable)
                                            <span class="badge badge-sm badge-soft badge-success shrink-0">Will be used</span>
                                        @elseif ($state === VerdictState::Error)
                                            <span class="badge badge-sm badge-soft badge-warning shrink-0">Couldn’t check</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                            @php $unchecked = $resolution->errors(); @endphp
                            @if ($unchecked !== [])
                                <span class="text-xs text-warning">
                                    {{ collect($unchecked)->pluck('label')->join(', ', ' and ') }}
                                    {{ count($unchecked) === 1 ? 'was passed over because its check failed' : 'were passed over because their checks failed' }}, not because the project doesn’t qualify.
                                </span>
                            @endif
                        </div>
                    @endif

                    @if ($migrates)
                        <div class="flex flex-col gap-2">
                            <label class="flex items-center gap-2.5 text-[13px]">
                                <input type="checkbox" class="checkbox checkbox-sm" wire:model="backup" @disabled(! $canBackup || $skipMigrations)>
                                <span>Back up the database before migrating</span>
                                @unless ($canBackup)
                                    <span class="text-xs text-muted">no Postgres settings in the env files</span>
                                @endunless
                            </label>
                            <label class="flex items-center gap-2.5 text-[13px]">
                                <input type="checkbox" class="checkbox checkbox-sm" wire:model.live="skipMigrations">
                                <span>Skip migrations</span>
                            </label>
                        </div>
                    @endif
                </div>

                <footer class="flex items-center gap-2 px-5 py-3 border-t border-line shrink-0">
                    @if (! $canDeploy && $picked->isNotEmpty())
                        <span class="text-xs text-error">Nothing can deliver this project right now.</span>
                    @endif
                    <button type="button" class="btn btn-ghost btn-sm ml-auto" wire:click="close">Cancel</button>
                    {{-- One click: the button spins and ignores more clicks until the run page opens,
                         and comes back only when deploy() answers without starting anything. deploy()
                         is idempotent for the dialog's attempt as well. --}}
                    <button type="button" class="btn btn-primary btn-sm" @disabled(! $canDeploy)
                            x-data="{ busy: false }" x-bind:class="{ 'btn-disabled': busy }" x-bind:aria-disabled="busy"
                            x-on:click="if (busy) return; busy = true; $wire.deploy().then(started => busy = started === true, () => busy = false)">
                        <x-icon :name="$willWait ? 'clock' : 'deploy'" :size="14" x-show="! busy" />
                        <x-icon name="loader" :size="14" class="animate-spin" x-show="busy" x-cloak />
                        Deploy {{ $selected ? substr($selected, 0, 7).' ' : '' }}to {{ $where }}{{ $willWait ? ' when CI passes' : '' }}
                    </button>
                </footer>
            </div>
            <div class="modal-backdrop" wire:click="close"></div>
        </div>
    @endif
</div>
