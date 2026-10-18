{{-- An action's runs, newest first ($runs: deployments of kind action). --}}
@if ($runs->isEmpty())
    <p class="px-4 py-4 text-[13px] text-muted">It hasn’t run yet.</p>
@else
    <div class="overflow-x-auto">
        <table class="table table-sm">
            <tbody>
                @foreach ($runs as $run)
                    <tr wire:key="action-run-{{ $run->id }}">
                        <td class="mono"><a href="{{ route('deployments.show', $run) }}" wire:navigate class="link">{{ $run->number() }}</a></td>
                        <td><span class="badge badge-sm {{ $run->status->badgeClass() }}">{{ $run->status->label() }}</span> <span class="text-muted">{{ $run->started_at ? $run->durationLabel() : '' }}</span></td>
                        <td class="text-muted">{{ $run->server?->name }}</td>
                        <td class="text-muted mono">{{ \App\Livewire\Projects\Actions::inputsLine($run) }}</td>
                        <td class="text-muted">{{ \App\Livewire\Projects\Actions::startedBy($run) }} · {{ $run->created_at->diffForHumans() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
