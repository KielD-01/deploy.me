<div class="flex flex-col gap-5">
    <x-page-header title="Projects"
                   :subtitle="$total === 0 ? 'Imported from GitHub or a local folder' : $total.' '.Str::plural('project', $total).' · '.$onGithub.' on GitHub, '.($total - $onGithub).' local '.Str::plural('folder', $total - $onGithub)">
        <x-slot:actions>
            @if ($total > 0)
                <a href="{{ route('projects.import') }}" wire:navigate class="btn btn-primary"><x-icon name="plus" /> Import project</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($total === 0)
        <x-empty-state icon="repo" title="No projects yet">
            Import a repository from GitHub, or a folder under ~/Personal, ~/Claude, ~/Projects or ~/Cursor.
            deploy.me reads its workflows, Compose files and framework, and suggests how to deploy it.
            <x-slot:actions>
                <a href="{{ route('projects.import') }}" wire:navigate class="btn btn-primary"><x-icon name="plus" /> Import project</a>
            </x-slot:actions>
        </x-empty-state>
    @else
        <div class="flex items-center gap-3">
            <div class="join">
                @foreach (['all' => 'All', 'github' => 'GitHub', 'local' => 'Local'] as $key => $label)
                    <button type="button" wire:click="$set('source', @js($key))" @class(['btn btn-sm join-item', 'btn-active' => $source === $key])>{{ $label }}</button>
                @endforeach
            </div>
            <label class="input input-sm w-72">
                <x-icon name="search" :size="14" class="text-muted" />
                <input type="search" wire:model.live.debounce.250ms="q" placeholder="Search projects">
            </label>
        </div>

        <section class="card bg-base-100 border border-line overflow-x-auto">
            <table class="table">
                <thead>
                    <tr><th>Project</th><th>Source</th><th>Deploy mode</th><th>Targets</th><th>Last deploy</th><th>CI</th></tr>
                </thead>
                <tbody>
                    @forelse ($projects as $project)
                        <tr wire:key="project-{{ $project->id }}" class="hover:bg-base-200/60">
                            <td>
                                <a href="{{ route('projects.show', $project) }}" wire:navigate class="flex flex-col">
                                    <span class="font-semibold">{{ $project->name }}</span>
                                    <span class="text-xs text-muted">{{ $project->framework ?? 'No framework recognised' }}</span>
                                </a>
                            </td>
                            <td>
                                <span class="flex items-center gap-2 text-[13px]">
                                    <x-icon :name="$project->source === \App\Enums\ProjectSource::Github ? 'repo' : 'laptop'" :size="14" class="text-muted" />
                                    <span class="mono">{{ $project->sourceLabel() }}</span>
                                </span>
                            </td>
                            <td>
                                <div class="flex flex-col text-[13px]">
                                    <span>{{ $project->deployModeLabel() }}</span>
                                    @if ($project->dockerized)
                                        <span class="text-xs text-muted">Docker · {{ $project->stack_name }}</span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <div class="flex flex-wrap gap-1">
                                    @forelse ($project->targets as $target)
                                        <span class="badge badge-sm badge-ghost gap-1"><x-icon name="server" :size="12" class="text-muted" /> {{ $target->server->name }}</span>
                                    @empty
                                        <span class="text-[13px] text-muted">Not assigned</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="text-[13px]">
                                @if ($run = $project->latestDeployment)
                                    <a href="{{ route('deployments.show', $run) }}" wire:navigate class="flex flex-col">
                                        <span>{{ $run->kind->label() }} {{ $run->number() }} · {{ Str::lower($run->status->label()) }}</span>
                                        <span class="text-xs text-muted">{{ $run->created_at->diffForHumans() }}</span>
                                    </a>
                                @else
                                    <span class="text-muted">Never</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex flex-col items-start gap-1">
                                    @if ($project->ci_state)
                                        <span class="badge badge-sm gap-1 {{ $project->ci_state->badgeClass() }}">
                                            <x-icon :name="$project->ci_state->icon()" :size="12" @class(['animate-spin' => $project->ci_state === \App\Enums\CiState::Running]) />
                                            {{ $project->ci_state->label() }}
                                        </span>
                                    @else
                                        <span class="text-xs text-muted">Checking…</span>
                                    @endif
                                    @if ($build = $green[$project->id] ?? null)
                                        <a href="{{ route('projects.show', $project) }}" wire:navigate class="badge badge-sm badge-soft badge-success gap-1"
                                           title="{{ $build['short'] }} passed CI and isn’t live on {{ implode(', ', $build['servers']) }}">
                                            <x-icon name="deploy" :size="12" /> New build ready
                                        </a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-[13px] text-muted py-6">No projects match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    @endif
</div>
