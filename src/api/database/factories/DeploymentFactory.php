<?php

namespace Database\Factories;

use App\Enums\DeploymentKind;
use App\Models\Deployment;
use App\Models\Target;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deployment>
 */
class DeploymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'target_id' => Target::factory(),
            'project_id' => fn (array $attributes) => Target::query()->find($attributes['target_id'])->project_id,
            'server_id' => fn (array $attributes) => Target::query()->find($attributes['target_id'])->server_id,
            'kind' => DeploymentKind::Bootstrap,
        ];
    }
}
