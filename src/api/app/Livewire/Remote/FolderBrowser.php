<?php

namespace App\Livewire\Remote;

use App\Platform\Remote\RemoteFs;
use App\Platform\Ssh\Exceptions\SshException;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Modelable;
use Livewire\Component;

/**
 * Browses folders on a server over SSH. The folder being viewed is the
 * selection, bound to the parent with wire:model.
 *
 * Give it a folder that exists to open (RemoteFs::nearestFolder finds one):
 * in the browser the parent's value wins, so a folder it picks for itself
 * while mounting never reaches the parent, and the next click would start
 * from the parent's value instead.
 */
class FolderBrowser extends Component
{
    #[Locked]
    public string $alias;

    #[Modelable]
    public string $path = '';

    /** @var list<string> */
    #[Locked]
    public array $reserved = [];

    /** @var list<array<string, mixed>> */
    public array $folders = [];

    public bool $writable = false;

    /** @var array<string, mixed>|null */
    public ?array $filesystem = null;

    public bool $truncated = false;

    public ?string $error = null;

    public bool $creating = false;

    public string $newFolder = '';

    /**
     * @param  list<string>  $reserved
     */
    public function mount(string $alias, array $reserved = []): void
    {
        $this->alias = $alias;
        $this->reserved = array_values(array_unique([...config('deployme.reserved_paths', []), ...$reserved]));
        $this->load($this->path !== '' ? $this->path : null);
    }

    public function open(string $name): void
    {
        $this->load(rtrim($this->path, '/').'/'.$name);
    }

    public function up(): void
    {
        $this->load(dirname($this->path));
    }

    public function go(string $path): void
    {
        $this->load($path);
    }

    public function refresh(): void
    {
        $this->load($this->path);
    }

    public function createFolder(RemoteFs $fs): void
    {
        $name = trim($this->newFolder);

        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/') || strlen($name) > 255) {
            $this->error = 'Use a folder name without slashes.';

            return;
        }

        $target = rtrim($this->path, '/').'/'.$name;

        if ($this->isReserved($target)) {
            $this->error = "{$target} is reserved for the shared infrastructure.";

            return;
        }

        try {
            $fs->mkdir($this->alias, $target);
        } catch (SshException $e) {
            $this->error = 'Couldn’t create the folder: '.$e->getMessage();

            return;
        }

        $this->reset('newFolder', 'creating');
        $this->load($target);
    }

    public function isReserved(string $path): bool
    {
        foreach ($this->reserved as $reserved) {
            if ($path === $reserved || str_starts_with(rtrim($path, '/').'/', rtrim($reserved, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    private function load(?string $path): void
    {
        $fs = app(RemoteFs::class);

        try {
            $path ??= $fs->isDirectory($this->alias, '/opt') ? '/opt' : $fs->home($this->alias);
            $listing = $fs->list($this->alias, $path);
        } catch (SshException $e) {
            $this->error = $e->userMessage();

            return;
        } catch (InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->path = $listing->path;
        $this->writable = $listing->writable;
        $this->filesystem = $listing->filesystem;
        $this->truncated = $listing->truncated;
        $this->error = null;

        $base = rtrim($listing->path, '/');
        $this->folders = [];
        foreach ($listing->entries as $entry) {
            if ($entry->isDir) {
                $this->folders[] = [...$entry->toArray(), 'reserved' => $this->isReserved("{$base}/{$entry->name}")];
            }
        }
    }

    public function render(): View
    {
        return view('livewire.remote.folder-browser');
    }
}
