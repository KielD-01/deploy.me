<?php

namespace App\Livewire\Audit;

use App\Enums\AuditAction;
use App\Models\AuditEntry;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The audit log: who did what, to which object, when, and how it ended.
 * Filterable by server and by project, and by whether it was someone's doing
 * or a command a deploy or a probe ran.
 */
#[Title('Audit log')]
class Index extends Component
{
    use WithPagination;

    public const PER_PAGE = 50;

    #[Url]
    public string $server = '';

    #[Url]
    public string $project = '';

    // all | by_hand | commands
    #[Url]
    public string $kind = 'all';

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $entries = AuditEntry::query()
            ->forServer($this->server !== '' ? (int) $this->server : null)
            ->forProject($this->project !== '' ? (int) $this->project : null)
            ->when($this->kind === 'by_hand', fn ($query) => $query->byHand())
            ->when($this->kind === 'commands', fn ($query) => $query->where('action', AuditAction::RemoteCommand->value))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return view('livewire.audit.index', [
            'entries' => $entries,
            'servers' => Server::query()->orderBy('name')->pluck('name', 'id'),
            'projects' => Project::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }
}
