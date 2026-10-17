<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Server;
use App\Models\Target;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Target>
 */
class TargetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'server_id' => Server::factory(),
            'base_dir' => '/opt/srv',
            'path' => fn (array $attributes) => Target::pathFor($attributes['base_dir'], Project::query()->find($attributes['project_id'])->slug),
        ];
    }
}
