<?php

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Server;
use App\Models\SyncRun;
use Database\Seeders\DemoSeeder;

// The demo the gallery's screenshots come from: it has to keep running
// through deploy.me's own code as that changes.
it('seeds the demo: servers, projects and a week of runs', function () {
    config(['deployme.project_roots' => ['/Users/demo/Projects'], 'deployme.host_home' => '/Users/demo']);

    $this->seed(DemoSeeder::class);

    expect(Server::query()->pluck('slug')->all())->toEqualCanonicalizing(['edge-fra', 'stage-ams', 'build-box'])
        ->and(Project::query()->pluck('slug')->all())->toEqualCanonicalizing(['warehouse', 'atelier', 'reviews'])
        ->and(Deployment::query()->where('status', DeploymentStatus::Succeeded)->count())->toBeGreaterThan(15)
        ->and(Deployment::query()->pluck('status')->all())->toContain(DeploymentStatus::RolledBack, DeploymentStatus::Failed)
        ->and(SyncRun::query()->count())->toBe(2);
});

it('fills only an empty database', function () {
    Server::factory()->create();

    expect(fn () => $this->seed(DemoSeeder::class))->toThrow(RuntimeException::class, 'only fills an empty database');
});
