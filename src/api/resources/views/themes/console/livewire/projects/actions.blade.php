{{-- The Console theme's Actions tab (App\Livewire\Projects\Actions): `ls -l` of the actions beside
     the selected one's YAML and its runs. Enter (or r) runs the selected action, e edits one made
     here, n makes a new one, u reloads the repo's. --}}
<div class="flex flex-wrap items-start gap-[22px_14px] px-[14px] pt-[22px] pb-4">
    @php
        use App\Livewire\Projects\Actions;
        use App\Enums\DeploymentStatus;
        use Illuminate\Support\Str;

        $tone = fn (?DeploymentStatus $status) => match ($status) {
            DeploymentStatus::Succeeded => 't-ok',
            DeploymentStatus::Failed, DeploymentStatus::Stalled => 't-err',
            DeploymentStatus::RolledBack, DeploymentStatus::Cancelled => 't-warn',
            null => 't-faint',
            default => 't-run',
        };
        $mark = fn (?DeploymentStatus $status) => match ($status) {
            DeploymentStatus::Succeeded => '✓',
            DeploymentStatus::Failed, DeploymentStatus::Stalled => '✗',
            DeploymentStatus::Cancelled => '■',
            null => '—',
            default => '●',
        };
        $currentDefinition = $current?->definition();
    @endphp

    <div class="basis-full min-w-0 flex flex-wrap items-center gap-x-4 gap-y-1">
        <span class="t-mute">─ actions ─ {{ $actions->count() }} · {{ $fromRepo }} from the repo (.deployme/actions) · {{ $actions->count() - $fromRepo }} made here</span>
        <nav class="con-keys ml-auto" aria-label="Action keys">
            @if ($currentDefinition?->manual())
                <button type="button" class="con-key is-go" data-key="r" @disabled(! $hasTargets) wire:click="$dispatch('open-action-dialog', { actionId: {{ $current->id }} })"><kbd>r</kbd> run {{ $current->key }}</button>
            @endif
            @if ($current && ! $current->fromRepo())
                <a href="{{ route('projects.actions.show', [$project, $current]) }}" wire:navigate class="con-key" data-key="e"><kbd>e</kbd> edit</a>
            @elseif ($current)
                <a href="{{ route('projects.actions.show', [$project, $current]) }}" wire:navigate class="con-key" data-key="e"><kbd>e</kbd> view</a>
            @endif
            <a href="{{ route('projects.actions.create', $project) }}" wire:navigate class="con-key" data-key="n"><kbd>n</kbd> new</a>
            <button type="button" class="con-key" data-key="u" wire:click="reload" wire:loading.attr="disabled" wire:target="reload">
                <kbd>u</kbd> <span wire:loading.remove wire:target="reload">reload from repo</span><span wire:loading wire:target="reload" class="t-run">reading…</span>
            </button>
        </nav>
    </div>

    @if ($notice)
        <div role="status" class="basis-full con-line t-ok">✓ {{ $notice }}</div>
    @endif
    @if ($problem)
        <div role="alert" class="basis-full con-line t-err">✕ {{ $problem }}</div>
    @endif

    <section class="con-box px-0 grow-[999] basis-[560px] min-w-0" aria-label="Actions">
        <span class="con-box-t"><b>actions</b> · ls -l</span>
        @if ($actions->isEmpty())
            <p class="t-mute px-3">No actions yet. Commit YAML files to .deployme/actions/ and press u, or n to make one here.</p>
        @else
            <div class="overflow-x-auto px-3">
                <table class="con-table">
                    <thead><tr><th></th><th>NAME</th><th>ON</th><th>STEPS</th><th>FROM</th><th>LAST RUN</th></tr></thead>
                    <tbody>
                        @foreach ($actions as $action)
                            @php
                                $definition = $action->definition();
                                $last = $action->latestRun;
                                $isSelected = $current?->id === $action->id;
                            @endphp
                            <tr wire:key="con-action-{{ $action->id }}" @class(['cursor-pointer', 'is-sel' => $isSelected]) wire:click="select(@js($action->key))">
                                <td class="t-run">{{ $isSelected ? '›' : '' }}</td>
                                <td class="whitespace-nowrap {{ $isSelected ? 't-b' : '' }}">
                                    {{ $action->key }}@if ($definition?->passkey()) <span class="t-warn" title="Asks for your passkey">⚿</span>@endif
                                </td>
                                <td class="whitespace-nowrap">
                                    @if ($definition)
                                        {{ implode(' · ', array_filter([$definition->manual() ? 'manual' : null])) }}@if ($definition->schedule() || $definition->afterDeploy())<span class="t-mute">{{ $definition->manual() ? ' · ' : '' }}{{ implode(' · ', array_filter([$definition->scheduleLabel(), $definition->afterDeploy() ? 'after deploy' : null])) }}</span>@endif
                                    @else
                                        <span class="t-err">errors</span>
                                    @endif
                                </td>
                                <td>{{ $definition ? count($definition->steps()) : '—' }}</td>
                                <td class="t-mute">{{ $action->fromRepo() ? 'repo' : 'here' }}</td>
                                <td class="whitespace-nowrap">
                                    @if ($last)
                                        <a href="{{ route('deployments.show', $last) }}" wire:navigate class="hover:underline" wire:click.stop><span class="{{ $tone($last->status) }}">{{ $mark($last->status) }}</span> {{ $last->created_at->diffForHumans(short: true) }} <span class="t-mute">{{ $last->server?->slug }}</span></a>
                                    @else
                                        <span class="t-faint">— never</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <p class="t-faint px-3 pt-2">⚿ asks for your passkey · repo actions are read from the head of {{ $project->branch }}; edit them in git · every run takes the deploy lock</p>
    </section>

    <div class="grow basis-[420px] min-w-[min(280px,100%)] flex flex-col gap-[22px]">
        <section class="con-box min-w-0" aria-label="Definition">
            @if ($current === null)
                <span class="con-box-t"><b>definition</b></span>
                <p class="t-mute">Pick an action.</p>
            @else
                <span class="con-box-t"><b>{{ $current->fromRepo() ? $current->file : $current->key.' (made here)' }}</b>@if ($current->commit_sha) · @ {{ substr($current->commit_sha, 0, 7) }}@endif</span>
                @if ($yaml !== null)
                    <pre class="overflow-x-auto leading-[1.7]">@foreach (explode("\n", rtrim($yaml)) as $n => $line)<span class="t-faint inline-block w-7 text-right mr-3 select-none">{{ $n + 1 }}</span>@if (preg_match('/^(\s*-?\s*)([A-Za-z0-9_-]+)(:)(.*)$/', $line, $m))<span>{{ $m[1] }}</span><span class="t-run">{{ $m[2] }}</span>{{ $m[3] }}{{ $m[4] }}@else{{ $line }}@endif
@endforeach</pre>
                @else
                    @foreach ($current->errors ?? [] as $error)
                        <p class="t-err">✕ {{ $error }}</p>
                    @endforeach
                @endif
            @endif
        </section>

        <section class="con-box min-w-0" aria-label="Runs of the action">
            <span class="con-box-t"><b>runs</b>{{ $current ? ' of '.$current->key : '' }}</span>
            @if ($selectedRuns->isEmpty())
                <p class="t-mute">No runs yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="con-table">
                        <tbody>
                            @foreach ($selectedRuns as $run)
                                <tr wire:key="con-action-run-{{ $run->id }}">
                                    <td><a href="{{ route('deployments.show', $run) }}" wire:navigate class="t-run hover:underline">{{ $run->number() }}</a></td>
                                    <td class="whitespace-nowrap"><span class="{{ $tone($run->status) }}">{{ $mark($run->status) }}</span> {{ $run->started_at ? $run->durationLabel() : Str::lower($run->status->label()) }}</td>
                                    <td>{{ $run->server?->slug }}</td>
                                    <td class="t-mute">{{ Actions::inputsLine($run) }}</td>
                                    <td class="t-mute whitespace-nowrap">{{ Actions::startedBy($run) }} · {{ $run->created_at->diffForHumans(short: true) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</div>
