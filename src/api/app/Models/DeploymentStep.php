<?php

namespace App\Models;

use App\Enums\StepStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['key', 'label', 'position', 'status', 'detail', 'exit_code', 'meta', 'started_at', 'finished_at'])]
class DeploymentStep extends Model
{
    public $timestamps = false;

    // Milliseconds: most steps take well under a second.
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => StepStatus::class,
            'position' => 'integer',
            'exit_code' => 'integer',
            'meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function durationSeconds(): ?float
    {
        if ($this->started_at === null) {
            return null;
        }

        return max(0, ($this->finished_at ?? now())->getTimestampMs() - $this->started_at->getTimestampMs()) / 1000;
    }

    public function durationLabel(): ?string
    {
        $seconds = $this->durationSeconds();

        return match (true) {
            $seconds === null => null,
            $seconds < 10 => number_format($seconds, 1).'s',
            $seconds < 60 => round($seconds).'s',
            default => intdiv((int) $seconds, 60).'m '.str_pad((string) ((int) $seconds % 60), 2, '0', STR_PAD_LEFT).'s',
        };
    }
}
