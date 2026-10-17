<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['version', 'content', 'content_hash', 'created_at'])]
class ProjectEnvVersion extends Model
{
    public $timestamps = false;

    protected $hidden = ['content'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'content' => 'encrypted',
            'created_at' => 'datetime',
        ];
    }

    public function envFile(): BelongsTo
    {
        return $this->belongsTo(ProjectEnvFile::class, 'project_env_file_id');
    }
}
