<div class="flex flex-col gap-5">
    @php use App\Enums\DeploymentStatus; @endphp

    <x-page-header title="Deployments" subtitle="Every deploy, rollback and prepare run, newest first" />

    <div class="flex flex-wrap items-center gap-3">
        <div class="join">
            @foreach ($filters as $key => $label)
                <button type="button" wire:click="$set('status', @js($key))" @class(['btn btn-sm join-item', 'btn-active' => $status === $key])>{{ $label }}</button>
            @endforeach
        </div>
        <select wire:model.live="project" class="select select-sm w-52" aria-label="Project">
            <option value="">All projects</option>
            @foreach ($projects as $choice)
                <option value="{{ $choice->id }}">{{ $choice->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="server" class="select select-sm w-52" aria-label="Server">
            <option value="">All servers</option>
            @foreach ($servers as $choice)
                <option value="{{ $choice->id }}">{{ $choice->name }}</option>
            @endforeach
        </select>
    </div>

    @if ($runs->isEmpty() && ! $filtered)
        <x-empty-state icon="deploy" title="No runs yet">
            Prepare a target or deploy a project, and every run shows up here with its steps and log.
            <x-slot:actions>
                <a href="{{ route('projects.index') }}" wire:navigate class="btn btn-primary"><x-icon name="repo" /> Go to projects</a>
            </x-slot:actions>
        </x-empty-state>
    @else
        <section class="card bg-base-100 border border-line overflow-x-auto">
            <table class="table">
                <thead>
                    <tr><th>Run</th><th>Project → target</th><th>Commit</th><th>Strategy</th><th>Status</th><th>Duration</th><th>Started</th><th>Trigger</th></tr>
                </thead>
                <tbody>
                    @forelse ($runs as $run)
                        <tr wire:key="run-{{ $run->id }}" class="hover:bg-base-200/60">
                            <td><a href="{{ route('deployments.show', $run) }}" wire:navigate class="font-semibold mono">{{ $run->number() }}</a></td>
                            <td>
                                <a href="{{ route('deployments.show', $run) }}" wire:navigate>
                                    {{ $run->project->name }} <span class="text-muted">→</span> {{ $run->server->name }}
                                </a>
                            </td>
                            <td class="text-[13px]">
                                @if ($run->isAction())
                                    <span class="badge badge-sm badge-ghost">Action</span> {{ $run->title() }}
                                @elseif ($run->commit_sha)
                                    <span class="mono">{{ substr($run->commit_sha, 0, 7) }}</span> {{ Str::limit((string) $run->commit_message, 50) }}
                                @else
                                    <span class="text-muted">{{ $run->kind->label() }}</span>
                                @endif
                            </td>
                            <td class="text-[13px] text-muted whitespace-nowrap">{{ \App\Enums\DeliveryStrategyKey::tryFrom((string) $run->strategy)?->label() ?? '—' }}</td>
                            <td>
                                <span class="badge badge-sm gap-1 {{ $run->status->badgeClass() }}">
                                    <x-icon :name="$run->status->icon()" :size="12" @class(['animate-spin' => $run->status === DeploymentStatus::Running]) /> {{ $run->status->label() }}
                                </span>
                            </td>
                            <td class="text-[13px] tabular-nums">{{ $run->durationLabel() }}</td>
                            <td class="text-[13px] text-muted whitespace-nowrap">
                                @php
                                    $runAt = $run->created_at->forViewer();
                                @endphp
                                {{ $runAt->isToday() ? $runAt->format('H:i') : ($runAt->isYesterday() ? 'Yesterday' : $runAt->format('D j M')) }}
                            </td>
                            <td class="text-[13px] text-muted">{{ match ($run->trigger) { 'manual' => 'You', 'ci' => 'CI went green', 'retry' => $run->retry_of_id ? 'Retry of #'.$run->retry_of_id : 'Retry', 'mcp' => 'AI agent (MCP)', 'console' => 'You (console)', default => ucfirst($run->trigger) } }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-[13px] text-muted py-6">No runs match.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        {{ $runs->links() }}
    @endif
</div>
