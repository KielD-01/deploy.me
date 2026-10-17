<div class="flex flex-col gap-4">
    @php
        use App\Deploy\Pipeline\Steps\WritePins;
        use App\Enums\ReleaseStatus;
        use App\Support\Format;
        $project = $this->project;
    @endphp

    @if ($problem)
        <div role="alert" class="alert alert-soft alert-error text-[13px]"><x-icon name="x-circle" /> {{ $problem }}</div>
    @endif

    @if (! $target)
        <x-empty-state icon="layers" title="No releases yet">
            Assign {{ $project->name }} to a server on the Targets tab and deploy it. Every deploy shows up here.
        </x-empty-state>
    @else
        <div class="flex flex-wrap items-center gap-3">
            @if ($targets->count() > 1)
                <div class="join">
                    @foreach ($targets as $choice)
                        <button type="button" wire:click="pickTarget({{ $choice->id }})" wire:key="pick-{{ $choice->id }}" @class(['btn btn-sm join-item', 'btn-active' => $choice->id === $target->id])>{{ $choice->server->name }}</button>
                    @endforeach
                </div>
            @endif
            <span class="text-[13px] text-muted">
                @if ($project->versionable)
                    {{ $kept }} of {{ $project->keep_releases }} kept on {{ $target->server->name }} · the keep count is set in Settings
                @else
                    {{ $project->name }} deploys in place on {{ $target->server->name }}: each deploy updates {{ $target->path }}
                @endif
            </span>
        </div>

        @if ($releases->isEmpty())
            <x-empty-state icon="layers" title="Nothing deployed to {{ $target->server->name }} yet">
                {{ $project->versionable
                    ? 'Each deploy gets its own release folder. The newest '.$project->keep_releases.' stay, ready to roll back to.'
                    : 'Each deploy updates the same folder. This list keeps the history.' }}
            </x-empty-state>
        @else
            @if ($project->versionable && $releases->contains(fn ($release) => $release->migrations > 0))
                <div role="note" class="alert alert-soft alert-warning text-[13px]">
                    <x-icon name="alert" />
                    <span>
                        Migrations don’t roll back: switching to a release older than one that migrated keeps the schema it created.
                        @if ($releases->contains(fn ($release) => $release->backup !== null))
                            A pg_dump is taken before every migration (the newest {{ \App\Deploy\Pipeline\Steps\BackupDatabase::KEEP }} stay in .deploy/backups) if you need to restore one.
                        @endif
                    </span>
                </div>
            @endif

            <section class="card bg-base-100 border border-line overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr><th>Release</th><th>Commit</th><th>Status</th><th>Migrations</th><th class="text-right">Size</th><th class="text-right">Duration</th><th>Deployed</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($releases as $release)
                            @php
                                $run = $release->deployment;
                                $live = $release->id === $target->current_release_id;
                                $at = ($release->activated_at ?? $release->created_at)->forViewer();
                            @endphp
                            <tr wire:key="release-{{ $release->id }}">
                                <td class="mono">{{ $release->name }}</td>
                                <td class="text-[13px]">
                                    @if ($release->commit_sha)
                                        <span class="mono">{{ $release->shortSha() }}</span> {{ Str::limit((string) $release->commit_message, 48) }}
                                    @else
                                        <span class="text-muted">{{ $release->strategy?->label() ?? '—' }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($release->status === ReleaseStatus::Building && $run)
                                        <a href="{{ route('deployments.show', $run) }}" wire:navigate class="badge badge-sm badge-soft badge-info gap-1">
                                            <x-icon name="loader" :size="12" class="animate-spin" /> Building {{ $run->number() }}
                                        </a>
                                    @elseif ($live)
                                        <span class="badge badge-sm badge-soft badge-success gap-1"><x-icon name="check" :size="12" /> Current</span>
                                    @else
                                        <span class="badge badge-sm {{ $release->status->badgeClass() }}">{{ $release->status->label() }}</span>
                                    @endif
                                    @if ($release->adopted)
                                        <span class="badge badge-sm badge-ghost">Adopted</span>
                                    @endif
                                </td>
                                <td class="text-[13px]">
                                    @if ($release->migrations > 0)
                                        <span @class(['badge badge-sm gap-1', 'badge-ghost' => $live, 'badge-soft badge-warning' => ! $live])>
                                            @unless ($live)<x-icon name="alert" :size="12" />@endunless {{ $release->migrations }} ran
                                        </span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-right text-[13px] tabular-nums">{{ $release->size_bytes && $release->status !== ReleaseStatus::Pruned ? Format::bytes($release->size_bytes) : '—' }}</td>
                                <td class="text-right text-[13px] tabular-nums">{{ $run?->durationLabel() ?? '—' }}</td>
                                <td class="text-[13px] text-muted whitespace-nowrap">{{ $at->gt(now()->subDay()) ? $at->diffForHumans() : $at->format('D j M') }}</td>
                                <td class="text-right whitespace-nowrap">
                                    @if (! $live && $release->canRollBackTo($project))
                                        <button type="button" class="btn btn-xs gap-1" wire:click="rollBack({{ $release->id }})"
                                                wire:confirm="{{ $release->inReleasesFolder() ? 'Switch '.$target->server->name.' back to release '.$release->name.'?' : 'Put release '.$release->name.'’s images back on '.$target->server->name.', and bring the stack up on them?' }} The database keeps the schema newer releases migrated to.">
                                            <x-icon name="rollback" :size="12" /> Roll back
                                        </button>
                                    @elseif (! $live && ! $release->inReleasesFolder() && $release->commit_sha && $release->status === ReleaseStatus::Ready)
                                        <button type="button" class="btn btn-xs gap-1" wire:click="redeploy({{ $release->id }})"
                                                wire:confirm="Deploy {{ $release->shortSha() }} to {{ $target->server->name }} again?">
                                            <x-icon name="deploy" :size="12" /> Deploy again
                                        </button>
                                    @endif
                                    @if ($run)
                                        <a href="{{ route('deployments.show', $run) }}" wire:navigate class="btn btn-xs btn-ghost">Run {{ $run->number() }}</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>

            @if ($current?->image_pins)
                <section class="card bg-base-100 border border-line">
                    <header class="flex items-center gap-2.5 min-h-[52px] px-4 border-b border-line">
                        <h2 class="text-[15px] font-semibold">Image pins of the current release</h2>
                        <span class="text-[13px] text-muted">Compose reads them after the shared .env:</span>
                        <span class="text-[13px] text-muted mono">{{ ($current->inReleasesFolder() ? 'releases/'.$current->name.'/' : '').WritePins::FILE }}</span>
                    </header>
                    <dl class="p-4 grid grid-cols-[180px_minmax(0,1fr)] gap-y-2 gap-x-4 text-[13px] font-mono">
                        @foreach ($current->image_pins as $var => $value)
                            <dt class="text-muted">{{ $var }}</dt>
                            <dd class="break-all">{{ $value }}</dd>
                        @endforeach
                    </dl>
                </section>
            @endif
        @endif
    @endif
</div>
