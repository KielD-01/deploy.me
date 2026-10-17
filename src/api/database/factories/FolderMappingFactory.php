<?php

namespace Database\Factories;

use App\Enums\SyncDirection;
use App\Models\FolderMapping;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FolderMapping>
 */
class FolderMappingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'name' => 'Uploads',
            'direction' => SyncDirection::Push,
            'local_path' => sys_get_temp_dir().'/uploads',
            'remote_path' => '/opt/srv/app/uploads',
        ];
    }
}
