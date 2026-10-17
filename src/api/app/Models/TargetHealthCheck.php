<?php

namespace App\Models;

use App\Enums\TargetHealth;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one health check of a target found. Only the last result lives on the
 * target itself; these are the ones before it.
 */
#[Fillable(['target_id', 'health', 'detail', 'duration_ms', 'checked_at'])]
class TargetHealthCheck extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'health' => TargetHealth::class,
            'duration_ms' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }
}
