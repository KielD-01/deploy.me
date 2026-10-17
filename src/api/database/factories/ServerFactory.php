<?php

namespace Database\Factories;

use App\Enums\EnvTag;
use App\Enums\ServerStatus;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    protected $model = Server::class;

    public function definition(): array
    {
        $alias = fake()->unique()->slug(2);

        return [
            'name' => Str::headline($alias),
            'slug' => $alias,
            'ssh_alias' => $alias,
            'host_name' => fake()->ipv4(),
            'ssh_user' => 'deploy',
            'port' => 22,
            'identity_files' => ['~/.ssh/id_ed25519'],
            'env_tag' => EnvTag::Production,
            'status' => ServerStatus::Online,
            'default_deploy_path' => '/opt/srv',
            'reserved_paths' => ['/opt/infra'],
            'probe_interval' => 300,
            'next_probe_at' => now()->addMinutes(5),
        ];
    }
}
