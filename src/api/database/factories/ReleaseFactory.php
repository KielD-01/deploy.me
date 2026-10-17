<?php

namespace Database\Factories;

use App\Enums\ReleaseStatus;
use App\Models\Release;
use App\Models\Target;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Release>
 */
class ReleaseFactory extends Factory
{
    public function definition(): array
    {
        $name = now()->utc()->subMinutes(fake()->unique()->numberBetween(1, 50000))->format('Ymd-His');

        return [
            'target_id' => Target::factory(),
            'name' => $name,
            'path' => fn (array $attributes) => Target::query()->find($attributes['target_id'])->path.'/releases/'.$attributes['name'],
            'layout' => Release::IN_RELEASES,
            'commit_sha' => fake()->sha1(),
            'commit_message' => 'Update the landing page',
            'strategy' => 'push_archive',
            'status' => ReleaseStatus::Ready,
            'size_bytes' => 48_000_000,
            'activated_at' => now(),
        ];
    }
}
