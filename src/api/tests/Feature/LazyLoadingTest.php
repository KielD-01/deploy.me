<?php

use App\Livewire\Projects\Index;
use App\Livewire\Projects\Show as ProjectPage;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Target;
use App\Models\User;
use App\Support\Ui;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    Ui::reset();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('fails on a relation read without its eager load, outside production', function () {
    expect(Model::preventsLazyLoading())->toBeTrue();

    // Laravel only guards a result of two models or more.
    demoTarget();
    Target::factory()->create();
    $targets = Target::query()->get();

    expect(fn () => $targets->first()->project)->toThrow(LazyLoadingViolationException::class);
});

it('lists projects without hydrating their detection', function () {
    $project = Project::factory()->create(['name' => 'Warehouse', 'detection' => ['compose_files' => array_fill(0, 40, ['file' => 'compose.yaml'])]]);
    demoTarget();

    $component = Livewire::test(Index::class)->assertSee('Warehouse');

    $listed = $component->viewData('projects')->firstWhere('id', $project->id);

    expect($listed->getAttributes())->not->toHaveKeys(['detection', 'compose_files', 'delivery_strategies', 'ci_etag'])
        ->and($listed->getAttributes())->toHaveKeys(['name', 'slug', 'ci_state', 'ci_run']);
});

// Laravel only guards a result of two or more models, so the pages are walked
// on the demo's data (three servers, three projects, a week of runs) rather
// than on the one-row fixtures most tests use.
it('renders every page on the demo data without a lazy load', function (string $theme) {
    config(['deployme.project_roots' => ['/Users/demo/Projects'], 'deployme.host_home' => '/Users/demo']);
    $this->seed(DemoSeeder::class);
    // The seeder signs its user out when it is done.
    $this->actingAs($this->user);
    Ui::save($this->user, ['theme' => $theme]);

    $paths = ['/', '/servers', '/projects', '/deployments', '/audit', '/settings'];

    foreach (Server::query()->get() as $server) {
        foreach (['overview', 'stacks', 'checks', 'folders', 'settings'] as $tab) {
            $paths[] = "/servers/{$server->slug}?tab={$tab}";
        }
    }

    foreach (Project::query()->get() as $project) {
        foreach (array_keys(ProjectPage::TABS) as $tab) {
            $paths[] = "/projects/{$project->slug}?tab={$tab}";
        }
    }

    $paths[] = '/deployments/'.Deployment::query()->latest('id')->value('id');

    foreach ($paths as $path) {
        expect([$path => $this->get($path)->status()])->toBe([$path => 200]);
    }
})->with(['console', 'classic']);
