<?php

namespace App\Livewire\Projects;

use App\Enums\ProjectSource;
use App\Models\Project;
use App\Support\GreenBuild;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Projects')]
class Index extends Component
{
    #[Url]
    public string $source = 'all';

    #[Url]
    public string $q = '';

    // CI polls re-render the list as they land.
    #[On('echo-private:projects,.ci.updated')]
    public function refreshProjects(): void {}

    public function render(): View
    {
        // The list shows a few columns; the detection (a large JSON document) stays in the database.
        $all = Project::query()->active()
            ->select(['id', 'name', 'slug', 'source', 'local_path', 'github_owner', 'github_repo', 'framework', 'versionable', 'keep_releases', 'dockerized', 'stack_name', 'ci_state', 'ci_run'])
            ->with(['targets' => fn ($query) => $query->whereNull('archived_at')->with(['server', 'currentRelease']), 'latestDeployment'])
            ->orderBy('name')
            ->get();
        $needle = Str::lower(trim($this->q));

        $projects = $all
            ->filter(fn (Project $project) => $this->source === 'all' || $project->source->value === $this->source)
            ->filter(fn (Project $project) => $needle === '' || str_contains(Str::lower($project->name.' '.$project->sourceLabel()), $needle))
            ->values();

        return view('livewire.projects.index', [
            'projects' => $projects,
            'total' => $all->count(),
            'onGithub' => $all->where('source', ProjectSource::Github)->count(),
            // Projects whose branch has a green build no server is running yet.
            'green' => $projects->mapWithKeys(fn (Project $project) => [$project->id => GreenBuild::for($project, $project->targets)])->filter()->all(),
        ]);
    }
}
