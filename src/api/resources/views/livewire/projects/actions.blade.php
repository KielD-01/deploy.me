{{-- The project's Actions tab (App\Livewire\Projects\Actions): repo and app actions, when each
     runs, its last run, and the latest runs. Run opens the run dialog (RunActionDialog). --}}
<div class="flex flex-col gap-4">
    @php
        use App\Livewire\Projects\Actions;
        use Illuminate\Support\Str;
    @endphp

    @if ($notice)
        <div role="status" class="alert alert-soft alert-success text-[13px]"><x-icon name="check-circle" /> {{ $notice }}</div>
    @endif
    @if ($problem)
        <div role="alert" class="alert alert-soft alert-error text-[13px]"><x-icon name="x-circle" /> {{ $problem }}</div>
    @endif

    <section class="card bg-base-100 border border-line">
        <header class="flex items-center gap-2.5 min-h-[52px] px-4 py-2 border-b border-line flex-wrap">
            <div class="flex items-baseline gap-2.5 flex-wrap">
                <h2 class="text-[15px] font-semibold">Actions</h2>
                <span class="text-[13px] text-muted">Commands you run on a target whenever you need them</span>
            </div>
            <div class="ml-auto flex items-center gap-2">
                <button type="button" class="btn btn-sm" wire:click="reload" wire:loading.attr="disabled" wire:target="reload">
                    <x-icon name="refresh" :size="14" wire:loading.class="animate-spin" wire:target="reload" /> Reload from repo
                </button>
                <a href="{{ route('projects.actions.create', $project) }}" wire:navigate class="btn btn-sm"><x-icon name="plus" :size="14" /> New action</a>
            </div>
        </header>

        @if ($actions->isEmpty())
            <div class="px-4 py-10 text-center text-[13px] text-muted flex flex-col items-center gap-2">
                <x-icon name="play" :size="20" />
                <p class="max-w-lg">
                    No actions yet. Put YAML files in <span class="mono">.deployme/actions/</span> in the repository and reload,
                    or make one here: clear caches, reindex search, restart the workers — a named job you run on a server when you need it.
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Action</th><th>Runs when</th><th>Steps</th><th>Last run</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($actions as $action)
                            @php
                                $definition = $action->definition();
                                $last = $action->latestRun;
                            @endphp
                            <tr wire:key="action-{{ $action->id }}" class="align-top">
                                <td class="min-w-56">
                                    <div class="font-semibold flex items-center gap-1.5">
                                        {{ $action->name }}
                                        @if ($definition?->passkey())<x-icon name="key" :size="12" class="text-warning" title="Asks for your passkey" />@endif
                                    </div>
                                    <div @class(['text-xs text-muted', 'mono' => $action->fromRepo()])>{{ $action->origin() }}@if ($action->fromRepo() && $action->commit_sha) · {{ substr($action->commit_sha, 0, 7) }}@endif</div>
                                    @if ($action->errors)
                                        <ul class="mt-1.5 text-xs text-error flex flex-col gap-0.5 max-w-xl whitespace-normal">
                                            @foreach ($action->errors as $error)<li>✕ {{ $error }}</li>@endforeach
                                        </ul>
                                    @endif
                                </td>
                                <td>
                                    @if ($definition)
                                        <div class="flex flex-wrap gap-1">
                                            @if ($definition->manual())<span class="badge badge-sm badge-ghost">Manual</span>@endif
                                            @if ($definition->schedule())
                                                <span class="badge badge-sm badge-ghost" title="{{ $definition->schedule() }} · {{ $definition::timezone()->getName() }}">{{ Str::ucfirst($definition->scheduleLabel()) }}{{ $definition->scheduledTarget($project) ? ' · '.$definition->scheduledTarget($project)->server->name : '' }}</span>
                                            @endif
                                            @if ($definition->afterDeploy())
                                                <span class="badge badge-sm badge-ghost">{{ Str::ucfirst($definition->afterDeployLabel()) }}</span>
                                            @endif
                                            @if ($definition->passkey())<span class="badge badge-sm badge-soft badge-warning">Passkey</span>@endif
                                            @if ($definition->mcp())<span class="badge badge-sm badge-soft badge-info">MCP</span>@endif
                                        </div>
                                    @else
                                        <span class="badge badge-sm badge-soft badge-error">Has errors</span>
                                    @endif
                                </td>
                                <td class="text-muted text-[13px]">
                                    @if ($definition)
                                        {{ count($definition->steps()) }}@if ($definition->inputs() !== []) · inputs: {{ implode(', ', array_column($definition->inputs(), 'name')) }}@endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="text-[13px]">
                                    @if ($last)
                                        <a href="{{ route('deployments.show', $last) }}" wire:navigate class="flex flex-wrap items-center gap-1.5">
                                            <span class="badge badge-sm {{ $last->status->badgeClass() }}">{{ $last->status->label() }}</span>
                                            <span class="text-muted">{{ $last->created_at->diffForHumans() }} · {{ $last->server?->name }}</span>
                                        </a>
                                    @else
                                        <span class="text-muted">Never run</span>
                                    @endif
                                </td>
                                <td class="text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1">
                                        @if ($definition?->manual())
                                            <button type="button" class="btn btn-xs gap-1" @disabled(! $hasTargets) @if (! $hasTargets) title="Assign the project to a server first" @endif
                                                    wire:click="$dispatch('open-action-dialog', { actionId: {{ $action->id }} })">
                                                <x-icon name="play" :size="12" /> Run
                                            </button>
                                        @endif
                                        <div class="dropdown dropdown-end">
                                            <button type="button" tabindex="0" class="btn btn-xs btn-ghost btn-square" aria-label="More for {{ $action->name }}"><x-icon name="more" :size="14" /></button>
                                            <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box border border-line w-56 p-1 shadow-sm z-10">
                                                @if (! $action->fromRepo())
                                                    <li><a href="{{ route('projects.actions.show', [$project, $action]) }}" wire:navigate><x-icon name="pencil" :size="14" /> Edit</a></li>
                                                @endif
                                                @if ($definition)
                                                    <li><a href="{{ route('projects.actions.show', [$project, $action]) }}" wire:navigate><x-icon name="file" :size="14" /> Definition and runs</a></li>
                                                @endif
                                                @if (! $action->fromRepo())
                                                    <li>
                                                        <button type="button" class="text-error" wire:click="delete({{ $action->id }})" wire:confirm="Remove the “{{ $action->name }}” action? Its runs stay in the history.">
                                                            <x-icon name="trash" :size="14" /> Remove
                                                        </button>
                                                    </li>
                                                @else
                                                    <li class="menu-disabled"><span class="text-xs">Edited in git: {{ $action->file }}</span></li>
                                                @endif
                                            </ul>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <footer class="px-4 py-2.5 border-t border-line text-xs text-muted">
            Actions from the repo are read from the head of {{ $project->branch }} on import and on Reload from repo, and edited in git.
            Actions made here are edited on this page. Every run takes the folder’s deploy lock, so it never runs beside a deploy.
        </footer>
    </section>

    <section class="card bg-base-100 border border-line">
        <header class="flex items-center gap-2.5 min-h-[44px] px-4 py-2 border-b border-line">
            <h2 class="text-[15px] font-semibold">Recent runs</h2>
            <a href="{{ route('deployments.index') }}" wire:navigate class="ml-auto link text-[13px]">All runs</a>
        </header>
        @if ($recent->isEmpty())
            <p class="px-4 py-4 text-[13px] text-muted">No action has run yet.</p>
        @else
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <tbody>
                        @foreach ($recent as $run)
                            <tr wire:key="recent-{{ $run->id }}">
                                <td class="mono"><a href="{{ route('deployments.show', $run) }}" wire:navigate class="link">{{ $run->number() }}</a></td>
                                <td>{{ $run->title() }}</td>
                                <td class="text-muted">{{ $run->server?->name }}</td>
                                <td class="text-muted mono">{{ Actions::inputsLine($run) }}</td>
                                <td><span class="badge badge-sm {{ $run->status->badgeClass() }}">{{ $run->status->label() }}</span> <span class="text-muted">{{ $run->started_at ? $run->durationLabel() : '' }}</span></td>
                                <td class="text-muted">{{ Actions::startedBy($run) }} · {{ $run->created_at->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
