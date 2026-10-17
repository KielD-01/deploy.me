<?php

namespace App\Livewire\Servers;

use App\Actions\Folders\FolderSyncBusy;
use App\Actions\Folders\StartFolderSync;
use App\Enums\SyncDirection;
use App\Enums\SyncRunStatus;
use App\Livewire\Concerns\ConfirmsIdentity;
use App\Models\FolderMapping;
use App\Models\Server;
use App\Models\SyncRun;
use App\Platform\Local\ProjectRoots;
use App\Platform\Remote\RemoteFs;
use App\Platform\Ssh\Exceptions\SshException;
use App\Support\FolderMappingRules;
use App\Support\Format;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A server's folder mappings: folders on the Mac synced one way with folders
 * on the server, previewed before anything is deleted.
 */
class Folders extends Component
{
    use ConfirmsIdentity;

    #[Locked]
    public int $serverId;

    public bool $formOpen = false;

    #[Locked]
    public ?int $editing = null;

    public string $name = '';

    public string $direction = 'push';

    public string $localPath = '';

    public string $remotePath = '';

    public string $excludes = '';

    public bool $deleteExtraneous = false;

    public string $schedule = '';

    public bool $browsingLocal = false;

    public bool $browsingRemote = false;

    // The path as it was before browsing, back in place if you close without choosing.
    #[Locked]
    public ?string $browseFrom = null;

    // The preview on screen.
    #[Locked]
    public ?int $previewId = null;

    public ?string $notice = null;

    // The run a "Syncing…" notice is about: the notice goes once it's done.
    #[Locked]
    public ?int $noticeRunId = null;

    public ?string $problem = null;

    public function mount(int $serverId): void
    {
        $this->serverId = $serverId;

        // Back from confirming with a passkey to turn deleting on: the form
        // waited in the session.
        $draft = session()->pull($this->draftKey());

        if (is_array($draft)) {
            foreach (['editing', 'name', 'direction', 'localPath', 'remotePath', 'excludes', 'schedule'] as $field) {
                if (array_key_exists($field, $draft)) {
                    $this->{$field} = $draft[$field];
                }
            }
            $this->formOpen = true;
            $this->deleteExtraneous = $this->identityConfirmed();
        }
    }

    #[Computed]
    public function server(): Server
    {
        return Server::query()->findOrFail($this->serverId);
    }

    // Syncs starting and finishing re-render the list and the preview. Once a
    // run with a notice is done, its row says how it went.
    #[On('echo-private:servers.{serverId},.sync')]
    public function refreshRuns(): void
    {
        if ($this->noticeRunId !== null && ! SyncRun::query()->whereKey($this->noticeRunId)->whereIn('status', SyncRunStatus::ACTIVE)->exists()) {
            $this->reset('notice', 'noticeRunId');
        }
    }

    public function startAdding(): void
    {
        $this->reset('notice', 'noticeRunId', 'problem');
        $this->resetForm();
        $this->formOpen = true;
        $this->remotePath = rtrim((string) $this->server->default_deploy_path, '/');
    }

    public function edit(int $id): void
    {
        $mapping = $this->mapping($id);

        $this->reset('notice', 'noticeRunId', 'problem');
        $this->resetForm();
        $this->formOpen = true;
        $this->editing = $mapping->id;
        $this->name = $mapping->name;
        $this->direction = $mapping->direction->value;
        $this->localPath = Format::homePath($mapping->local_path);
        $this->remotePath = $mapping->remote_path;
        $this->excludes = implode("\n", $mapping->excludes ?? []);
        $this->deleteExtraneous = $mapping->delete_extraneous;
        $this->schedule = (string) ($mapping->schedule_interval ?? '');
    }

    public function updatedLocalPath(): void
    {
        $this->suggestName($this->localPath);
    }

    public function updatedRemotePath(): void
    {
        $this->suggestName($this->remotePath);
    }

    public function updatedDeleteExtraneous(bool $value): void
    {
        $alreadyOn = $this->editing !== null && $this->mapping($this->editing)->delete_extraneous;

        if (! $value || $alreadyOn || $this->identityConfirmed()) {
            return;
        }

        // Deleting files at the destination needs a fresh passkey confirmation.
        session()->put($this->draftKey(), [
            'editing' => $this->editing,
            'name' => $this->name,
            'direction' => $this->direction,
            'localPath' => $this->localPath,
            'remotePath' => $this->remotePath,
            'excludes' => $this->excludes,
            'schedule' => $this->schedule,
        ]);
        $this->deleteExtraneous = false;
        $this->confirmIdentity(route('servers.show', ['server' => $this->server, 'tab' => 'folders']));
    }

    // The pickers open where the path points, or at the nearest folder above
    // it that exists: a folder that doesn't exist yet is fine to type in.
    public function browseLocal(ProjectRoots $roots): void
    {
        $this->browseFrom = $this->localPath;

        $path = $roots->normalize($this->localPath);
        $found = null;
        while (str_starts_with($path, '/') && ($found = $roots->resolve($path)) === null && $path !== '/') {
            $path = dirname($path);
        }

        $this->localPath = $found ?? '';
        $this->browsingLocal = true;
    }

    public function browseRemote(RemoteFs $fs): void
    {
        $this->browseFrom = $this->remotePath;

        $path = rtrim(trim($this->remotePath), '/');
        if (! str_starts_with($path, '/')) {
            $path = rtrim((string) $this->server->default_deploy_path, '/') ?: '/opt';
        }

        try {
            $path = $fs->nearestFolder($this->server->ssh_alias, $path);
        } catch (SshException|InvalidArgumentException) {
            // The browser says what's wrong when it tries to list the folder.
        }

        $this->remotePath = $path;
        $this->browsingRemote = true;
    }

    public function usePaths(): void
    {
        $this->reset('browsingLocal', 'browsingRemote', 'browseFrom');
    }

    public function closeBrowser(): void
    {
        if ($this->browsingLocal) {
            $this->localPath = (string) $this->browseFrom;
        }
        if ($this->browsingRemote) {
            $this->remotePath = (string) $this->browseFrom;
        }

        $this->usePaths();
    }

    public function save(StartFolderSync $start): void
    {
        $this->reset('notice', 'noticeRunId', 'problem');

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'direction' => ['required', Rule::enum(SyncDirection::class)],
            'localPath' => ['required', 'string', 'max:500'],
            'remotePath' => ['required', 'string', 'max:500'],
            'excludes' => ['nullable', 'string', 'max:10000'],
            'schedule' => ['nullable', Rule::in(array_keys(FolderMapping::SCHEDULES))],
        ], [], ['localPath' => 'folder on your Mac', 'remotePath' => 'folder on the server']);

        $mapping = $this->editing !== null ? $this->mapping($this->editing) : new FolderMapping(['server_id' => $this->serverId]);

        if ($this->deleteExtraneous && ! $mapping->delete_extraneous && ! $this->identityConfirmed()) {
            $this->deleteExtraneous = false;
            $this->problem = 'Deleting files needs a passkey confirmation. Tick the box again to confirm.';

            return;
        }

        $roots = app(ProjectRoots::class);
        $mapping->fill([
            'name' => trim($this->name),
            'direction' => $this->direction,
            'local_path' => $roots->normalize($this->localPath),
            'remote_path' => rtrim(trim($this->remotePath), '/'),
            'excludes' => FolderMappingRules::parseExcludes($this->excludes),
            'delete_extraneous' => $this->deleteExtraneous,
            'schedule_interval' => $this->schedule !== '' ? (int) $this->schedule : null,
        ]);
        $mapping->setRelation('server', $this->server);

        if (($broken = FolderMappingRules::check($mapping, $roots)) !== null) {
            $this->addError($broken[0], $broken[1]);

            return;
        }

        $mapping->next_run_at = $mapping->schedule_interval !== null ? now()->addSeconds($mapping->schedule_interval) : null;
        $mapping->save();

        $this->resetForm();
        $this->dispatch('folder-mappings-changed');
        $this->notice = "Saved {$mapping->name}. Here’s what a sync would change.";
        $this->startPreview($mapping, $start);
    }

    public function preview(int $id, StartFolderSync $start): void
    {
        $this->reset('notice', 'noticeRunId', 'problem');
        $this->startPreview($this->mapping($id), $start);
    }

    public function syncNow(int $id, StartFolderSync $start): void
    {
        $this->reset('notice', 'noticeRunId', 'problem');
        $mapping = $this->mapping($id);

        // Deleting always goes through a preview you apply.
        if ($mapping->delete_extraneous) {
            $this->startPreview($mapping, $start);
            $this->notice = "Deleting is on for {$mapping->name}, so this starts with a preview.";

            return;
        }

        try {
            $run = $start($mapping, dryRun: false, user: auth()->user());
        } catch (FolderSyncBusy $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->notice = "Syncing {$mapping->name}…";
        $this->noticeRunId = $run->id;
    }

    public function showRun(int $runId): void
    {
        $run = $this->runOnThisServer($runId);

        if ($run->dry_run) {
            $this->previewId = $run->id;
        }
    }

    public function apply(StartFolderSync $start): void
    {
        $preview = $this->previewId !== null ? $this->runOnThisServer($this->previewId) : null;

        if ($preview === null || ! $preview->dry_run || $preview->status !== SyncRunStatus::Succeeded) {
            return;
        }

        try {
            // What the preview showed is the most the real run may delete.
            $run = $start($preview->mapping, dryRun: false, user: auth()->user(), maxDelete: (int) ($preview->stats['deleted'] ?? 0));
        } catch (FolderSyncBusy $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->previewId = null;
        $this->notice = 'Applying '.$preview->change_count.' '.Str::plural('change', $preview->change_count)." to {$preview->mapping->name}…";
        $this->noticeRunId = $run->id;
    }

    public function discard(): void
    {
        $this->previewId = null;
    }

    public function cancelRun(int $runId): void
    {
        $run = $this->runOnThisServer($runId);

        $cancelled = SyncRun::query()->whereKey($run->id)->where('status', SyncRunStatus::Queued)->update([
            'status' => SyncRunStatus::Cancelled,
            'cancel_requested_at' => now(),
            'finished_at' => now(),
            'error' => 'Cancelled before it started.',
        ]);

        if ($cancelled === 0) {
            SyncRun::query()->whereKey($run->id)->where('status', SyncRunStatus::Running)->update(['cancel_requested_at' => now()]);
        }
    }

    public function remove(int $id): void
    {
        $this->reset('notice', 'noticeRunId', 'problem');
        $mapping = $this->mapping($id);

        if ($mapping->activeRun()->exists()) {
            $this->problem = "{$mapping->name} is syncing. Wait for it, or cancel it first.";

            return;
        }

        $mapping->delete();
        $this->dispatch('folder-mappings-changed');

        if ($this->previewId !== null && ! SyncRun::query()->whereKey($this->previewId)->exists()) {
            $this->previewId = null;
        }

        $this->notice = "Removed {$mapping->name}. Files on both sides stay where they are.";
    }

    private function startPreview(FolderMapping $mapping, StartFolderSync $start): void
    {
        try {
            $this->previewId = $start($mapping, dryRun: true, user: auth()->user())->id;
        } catch (FolderSyncBusy $e) {
            $this->problem = $e->getMessage();
        }
    }

    private function suggestName(string $path): void
    {
        if (trim($this->name) === '' && ($base = basename(rtrim(trim($path), '/'))) !== '') {
            $this->name = Str::headline($base);
        }
    }

    private function mapping(int $id): FolderMapping
    {
        return $this->server->folderMappings()->findOrFail($id);
    }

    private function runOnThisServer(int $runId): SyncRun
    {
        return SyncRun::query()
            ->whereKey($runId)
            ->whereHas('mapping', fn ($query) => $query->where('server_id', $this->serverId))
            ->with('mapping')
            ->firstOrFail();
    }

    private function draftKey(): string
    {
        return "deployme.folders.draft.{$this->serverId}";
    }

    private function resetForm(): void
    {
        $this->reset('formOpen', 'editing', 'name', 'direction', 'localPath', 'remotePath', 'excludes', 'deleteExtraneous', 'schedule', 'browsingLocal', 'browsingRemote', 'browseFrom');
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.servers.folders', [
            'mappings' => $this->server->folderMappings()->with(['lastRealRun', 'activeRun', 'latestRun'])->orderBy('name')->get(),
            'preview' => $this->previewId !== null ? SyncRun::query()->with('mapping')->find($this->previewId) : null,
            'schedules' => FolderMapping::SCHEDULES,
        ]);
    }
}
