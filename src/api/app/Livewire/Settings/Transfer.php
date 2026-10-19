<?php

namespace App\Livewire\Settings;

use App\Actions\Transfer\ExportProjects;
use App\Actions\Transfer\ImportProjects;
use App\Enums\AuditAction;
use App\Livewire\Concerns\ConfirmsIdentity;
use App\Models\Project;
use App\Support\Audit;
use App\Support\Transfer\TransferCode;
use App\Support\Transfer\TransferCodeInvalid;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Settings → Transfer: projects to another deploy.me as one code sealed with
 * a passphrase, and a code from another one read back in. The code carries
 * env files, so both ways ask for a passkey first. A passphrase never stays
 * in the page's state; a code being imported waits server-side, encrypted,
 * between the preview and the import.
 */
class Transfer extends Component
{
    use ConfirmsIdentity;

    private const TICKET_MINUTES = 15;

    /** @var list<string> slugs to export */
    public array $selected = [];

    public string $passphrase = '';

    public string $passphraseConfirmation = '';

    public ?string $code = null;

    public string $importCode = '';

    public string $importPassphrase = '';

    public ?string $ticket = null;

    /** @var list<array<string, mixed>>|null */
    public ?array $plan = null;

    public bool $keepAutoDeploy = false;

    /** @var array{projects: list<string>, targets: int, skipped: list<string>}|null */
    public ?array $result = null;

    public ?string $notice = null;

    public ?string $problem = null;

    public function mount(): void
    {
        $this->selected = Project::query()->active()->orderBy('name')->pluck('slug')->all();
    }

    public function confirm(): void
    {
        $this->confirmIdentity(route('settings', ['tab' => 'transfer']));
    }

    public function export(ExportProjects $export): void
    {
        $passphrase = $this->passphrase;
        $confirmation = $this->passphraseConfirmation;
        $this->reset('passphrase', 'passphraseConfirmation', 'code', 'notice', 'problem');

        if (! $this->confirmIdentity(route('settings', ['tab' => 'transfer']))) {
            return;
        }

        $this->problem = match (true) {
            $this->selected === [] => 'Pick at least one project.',
            mb_strlen($passphrase) < TransferCode::MIN_PASSPHRASE => 'Use a passphrase of at least '.TransferCode::MIN_PASSPHRASE.' characters: it’s all that guards the env files in the code.',
            $passphrase !== $confirmation => 'The two passphrases don’t match.',
            default => null,
        };

        if ($this->problem !== null) {
            return;
        }

        $payload = $export($this->selected);
        $this->code = TransferCode::seal($payload, $passphrase);
        $count = count($payload['projects']);

        Audit::record(AuditAction::ProjectsExported, [
            'subject' => $count.' '.Str::plural('project', $count),
            'detail' => collect($payload['projects'])->pluck('slug')->implode(', '),
        ]);
    }

    /**
     * Opens the code and shows what an import would do, changing nothing.
     */
    public function read(ImportProjects $import): void
    {
        $code = $this->importCode;
        $passphrase = $this->importPassphrase;
        $this->reset('importPassphrase', 'ticket', 'plan', 'result', 'notice', 'problem');

        if (! $this->confirmIdentity(route('settings', ['tab' => 'transfer']))) {
            return;
        }

        if (strlen($code) > 4 * 1024 * 1024) {
            $this->problem = 'That code is far too long to be one deploy.me made.';

            return;
        }

        try {
            $payload = TransferCode::open($code, $passphrase);
        } catch (TransferCodeInvalid $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->ticket = (string) Str::uuid();
        Cache::put(self::ticketKey($this->ticket), Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)), now()->addMinutes(self::TICKET_MINUTES));
        $this->plan = $import->plan($payload);
        $this->importCode = '';
    }

    public function import(ImportProjects $import): void
    {
        $this->reset('notice', 'problem');

        if (! $this->confirmIdentity(route('settings', ['tab' => 'transfer']))) {
            return;
        }

        $sealed = $this->ticket !== null ? Cache::pull(self::ticketKey($this->ticket)) : null;

        if (! is_string($sealed)) {
            $this->reset('ticket', 'plan');
            $this->problem = 'That code was read too long ago. Paste it again.';

            return;
        }

        $this->result = $import(json_decode(Crypt::decryptString($sealed), true, 64, JSON_THROW_ON_ERROR), $this->keepAutoDeploy);
        $this->reset('ticket', 'plan', 'keepAutoDeploy');
        $this->selected = Project::query()->active()->orderBy('name')->pluck('slug')->all();
    }

    public function cancelImport(): void
    {
        if ($this->ticket !== null) {
            Cache::forget(self::ticketKey($this->ticket));
        }

        $this->reset('ticket', 'plan', 'result', 'keepAutoDeploy', 'notice', 'problem');
    }

    public function render(): View
    {
        return view('livewire.settings.transfer', [
            'confirmed' => $this->identityConfirmed(),
            'projects' => Project::query()->active()->withCount(['targets' => fn ($query) => $query->active(), 'envFiles'])->orderBy('name')->get(),
        ]);
    }

    private static function ticketKey(string $ticket): string
    {
        return 'deployme:transfer:'.auth()->id().':'.$ticket;
    }
}
