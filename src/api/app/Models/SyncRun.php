<?php

namespace App\Models;

use App\Enums\SyncRunStatus;
use App\Support\Format;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One rsync run of a folder mapping: a dry run (the preview) or a real one.
 */
#[Fillable([
    'folder_mapping_id', 'user_id', 'dry_run', 'status', 'trigger', 'max_delete', 'stats', 'changes', 'change_count',
    'error', 'started_at', 'finished_at', 'cancel_requested_at',
])]
class SyncRun extends Model
{
    use HasFactory;

    protected $attributes = [
        'status' => 'queued',
        'trigger' => 'manual',
        'dry_run' => false,
        'change_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'status' => SyncRunStatus::class,
            'max_delete' => 'integer',
            'stats' => 'array',
            'changes' => 'array',
            'change_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
        ];
    }

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(FolderMapping::class, 'folder_mapping_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * @return array{new: int, updated: int, deleted: int, folders: int, links: int}
     */
    public function counts(): array
    {
        return ($this->stats['counts'] ?? []) + ['new' => 0, 'updated' => 0, 'deleted' => 0, 'folders' => 0, 'links' => 0];
    }

    /**
     * "12 files · 18.4 MB · 1 deleted"
     */
    public function summary(): string
    {
        $files = (int) ($this->stats['transferred'] ?? 0);
        $parts = [$files.' '.Str::plural('file', $files)];

        if (($bytes = (int) ($this->stats['transferred_bytes'] ?? 0)) > 0) {
            $parts[] = Format::bytes($bytes);
        }
        if (($deleted = (int) ($this->stats['deleted'] ?? 0)) > 0) {
            $parts[] = $deleted.' deleted';
        }

        return implode(' · ', $parts);
    }
}
