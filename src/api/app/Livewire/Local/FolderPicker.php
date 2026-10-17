<?php

namespace App\Livewire\Local;

use App\Models\Project;
use App\Platform\Local\ProjectRoots;
use App\Support\Format;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Modelable;
use Livewire\Component;

/**
 * Browses the project folders on the Mac, mounted into the containers at the
 * same paths. The folder being viewed is the selection, bound to the parent
 * with wire:model; an empty path lists the roots.
 */
class FolderPicker extends Component
{
    #[Modelable]
    public string $path = '';

    public function open(string $name): void
    {
        $this->go(rtrim($this->path, '/').'/'.$name);
    }

    public function go(string $path): void
    {
        // Anything outside the roots goes back to the list of roots.
        $this->path = app(ProjectRoots::class)->resolve($path) ?? '';
    }

    public function up(): void
    {
        $roots = app(ProjectRoots::class);
        $isRoot = in_array($this->path, array_column($roots->roots(), 'path'), true);

        $this->path = $isRoot ? '' : ($roots->resolve(dirname($this->path)) ?? '');
    }

    public function render(ProjectRoots $roots): View
    {
        return view('livewire.local.folder-picker', [
            'roots' => $roots->roots(),
            'folders' => $this->path === '' ? [] : $roots->folders($this->path),
            'imported' => Project::query()->active()->whereNotNull('local_path')->pluck('local_path')->all(),
            'display' => Format::homePath($this->path),
        ]);
    }
}
