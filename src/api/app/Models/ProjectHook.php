<?php

namespace App\Models;

use App\Enums\HookPhase;
use App\Enums\HookRunIn;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['phase', 'name', 'command', 'run_in', 'service', 'timeout', 'continue_on_error', 'position'])]
class ProjectHook extends Model
{
    protected function casts(): array
    {
        return [
            'phase' => HookPhase::class,
            'run_in' => HookRunIn::class,
            'timeout' => 'integer',
            'continue_on_error' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function where(): string
    {
        return $this->run_in->describe($this->service);
    }
}
