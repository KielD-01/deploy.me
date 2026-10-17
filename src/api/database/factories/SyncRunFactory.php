<?php

namespace Database\Factories;

use App\Models\FolderMapping;
use App\Models\SyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncRun>
 */
class SyncRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'folder_mapping_id' => FolderMapping::factory(),
        ];
    }
}
