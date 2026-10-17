<?php

namespace App\Models;

use App\Deploy\Logging\StepLogger;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Enums\ReleaseStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One run of the deploy engine against a target. Its id is the run number.
 */
#[Fillable([
    'target_id', 'project_id', 'server_id', 'user_id', 'release_id', 'previous_release_id', 'kind', 'status', 'trigger', 'commit_sha', 'commit_message',
    'strategy', 'strategy_trace', 'options', 'outcome', 'log_lines', 'started_at', 'finished_at', 'heartbeat_at',
    'cancel_requested_at', 'retry_of_id', 'resume_from',
])]
class Deployment extends Model
{
    use HasFactory;

    // How many lines a log pane keeps (resources/js/deploy-log.js): a pane
    // that loads a run starts this far from its end. Download has them all.
    public const LOG_PANE_LINES = 5000;

    // Lines per request when a pane catches up.
    public const LOG_PAGE = 2000;

    protected $attributes = [
        'status' => 'queued',
        'trigger' => 'manual',
        'log_lines' => 0,
    ];

    protected function casts(): array
    {
        return [
            'kind' => DeploymentKind::class,
            'status' => DeploymentStatus::class,
            'strategy_trace' => 'array',
            'options' => 'array',
            'log_lines' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    public function previousRelease(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'previous_release_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(DeploymentStep::class)->orderBy('position');
    }

    /**
     * The run this one retries, from the step in resume_from on.
     */
    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retry_of_id');
    }

    public function retries(): HasMany
    {
        return $this->hasMany(self::class, 'retry_of_id');
    }

    public function number(): string
    {
        return '#'.$this->id;
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * A run that stopped without settling (its worker died, or went quiet):
     * the release it was building never finished. It counts as failed, so it
     * stops showing as uploading and the next prune removes it.
     */
    public function failUnfinishedRelease(): void
    {
        if ($this->release_id !== null) {
            Release::query()->whereKey($this->release_id)->where('status', ReleaseStatus::Building)->update(['status' => ReleaseStatus::Failed]);
        }
    }

    public function durationSeconds(): ?int
    {
        return $this->started_at === null ? null : (int) $this->started_at->diffInSeconds($this->finished_at ?? now(), true);
    }

    public function durationLabel(): string
    {
        $seconds = $this->durationSeconds();

        return match (true) {
            $seconds === null => '—',
            $seconds >= 60 => intdiv($seconds, 60).'m '.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT).'s',
            default => $seconds.'s',
        };
    }

    /**
     * The lines a log pane asks for after the last one it has, in the shape
     * deploy-log.js takes: [seq, stream, step, logged at (UTC), text]. A pane
     * that has nothing yet, or fell far behind, starts LOG_PANE_LINES from
     * the end: it would drop the older ones anyway.
     *
     * @return list<array{0: int, 1: string, 2: ?string, 3: string, 4: string}>
     */
    public function linesForPane(int $afterSeq): array
    {
        $total = (int) self::query()->whereKey($this->id)->value('log_lines');

        return $this->logLines(max(0, $afterSeq, $total - self::LOG_PANE_LINES), self::LOG_PAGE)
            ->map(fn (object $line) => [(int) $line->seq, $line->stream, $line->step_key, StepLogger::instant((string) $line->logged_at), $line->line])
            ->values()
            ->all();
    }

    /**
     * The number of the last line logged, from the lines themselves: for a
     * run that ended without its runner (a worker that died, a stall).
     */
    public function loggedLines(): int
    {
        return (int) DB::table('deployment_logs')->where('deployment_id', $this->id)->max('seq');
    }

    /**
     * Log lines after a sequence number, oldest first; only one step's, given its key.
     *
     * @return Collection<int, object{seq: int, step_key: ?string, stream: string, line: string, logged_at: string}>
     */
    public function logLines(int $afterSeq = 0, int $limit = 2000, ?string $step = null): Collection
    {
        return DB::table('deployment_logs')
            ->where('deployment_id', $this->id)
            ->where('seq', '>', $afterSeq)
            ->when($step !== null, fn ($query) => $query->where('step_key', $step))
            ->orderBy('seq')
            ->limit($limit)
            ->get(['seq', 'step_key', 'stream', 'line', 'logged_at']);
    }
}
