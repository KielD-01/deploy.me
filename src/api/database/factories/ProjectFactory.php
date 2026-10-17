<?php

namespace Database\Factories;

use App\Enums\ProjectSource;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->slug(2);

        return [
            'name' => $name,
            'slug' => $name,
            'source' => ProjectSource::Github,
            'github_owner' => 'KielD-01',
            'github_repo' => $name,
            'branch' => 'main',
            'versionable' => true,
            'keep_releases' => 5,
            'dockerized' => false,
            'delivery_strategies' => [
                ['key' => 'push_archive', 'enabled' => true],
                ['key' => 'server_git', 'enabled' => false],
            ],
        ];
    }

    public function local(string $path): static
    {
        return $this->state(fn () => [
            'source' => ProjectSource::Local,
            'local_path' => $path,
            'github_owner' => null,
            'github_repo' => null,
        ]);
    }

    public function dockerized(string $stack = 'app'): static
    {
        return $this->state(fn () => [
            'dockerized' => true,
            'stack_name' => $stack,
            'compose_files' => ['compose.yaml'],
        ]);
    }
}
