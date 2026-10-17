<?php

namespace App\Deploy\Logging;

use App\Events\DeploymentLogged;
use App\Models\Deployment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A deployment's log: lines from the server and deploy.me's own notes,
 * cleaned of terminal codes, redacted, numbered, written in batches (one
 * insert per flush) and broadcast to the run page as they're written. The
 * run's line count (deployments.log_lines) follows less often: with the
 * heartbeat, at each step, and at the end (saveCount()).
 */
final class StepLogger
{
    private const MAX_LINES = 100;

    private const MAX_BYTES = 7000;

    private const MAX_WAIT = 0.25;

    // Reverb refuses messages over 10 000 bytes; bigger batches go as a gap
    // the page backfills.
    private const MAX_BROADCAST = 9000;

    private const MAX_LINE = 8000;

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    private int $bytes = 0;

    private int $seq;

    private float $flushedAt;

    private ?string $step = null;

    // The last line in the database, and the count the run row last got.
    private int $written;

    private int $counted;

    public function __construct(
        private readonly Deployment $deployment,
        public readonly SecretRedactor $redactor = new SecretRedactor,
    ) {
        $this->seq = (int) DB::table('deployment_logs')->where('deployment_id', $deployment->id)->max('seq');
        $this->written = $this->counted = $this->seq;
        $this->flushedAt = microtime(true);
    }

    /**
     * Lines from here on belong to this step.
     */
    public function forStep(?string $key): void
    {
        $this->flush();
        $this->saveCount();
        $this->step = $key;
    }

    /**
     * A line of output from the server.
     */
    public function line(string $line, string $stream = 'out'): void
    {
        $this->push($stream === 'err' ? 'err' : 'out', $line);
    }

    /**
     * A note from deploy.me itself: "▸ Pre-flight checks", "✗ …".
     */
    public function note(string $line): void
    {
        $this->push('sys', $line);
    }

    public function lastSeq(): int
    {
        return $this->seq;
    }

    public function flush(): void
    {
        $this->flushedAt = microtime(true);

        if ($this->buffer === []) {
            return;
        }

        $rows = $this->buffer;
        $this->buffer = [];
        $this->bytes = 0;

        DB::table('deployment_logs')->insert($rows);
        $this->written = $this->seq;

        $lines = array_map(
            fn (array $row) => [$row['seq'], $row['stream'], $row['step_key'], self::instant($row['logged_at']), $row['line']],
            $rows,
        );

        try {
            DeploymentLogged::dispatch(
                $this->deployment->id,
                strlen((string) json_encode($lines)) <= self::MAX_BROADCAST ? $lines : [],
                $rows[0]['seq'],
                $this->seq,
            );
        } catch (Throwable $e) {
            // The log is in the database either way; the page catches up on reload.
            Log::warning('Could not broadcast deploy log lines', ['deployment' => $this->deployment->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Writes what's buffered and the run's line count: the last flush of a run.
     */
    public function close(): void
    {
        $this->flush();
        $this->saveCount();
    }

    /**
     * deployments.log_lines: the lines in the database so far, when that changed.
     */
    public function saveCount(): void
    {
        if ($this->written === $this->counted) {
            return;
        }

        DB::table('deployments')->where('id', $this->deployment->id)->update(['log_lines' => $this->written]);
        $this->counted = $this->written;
    }

    /**
     * When a line was logged, as the UTC instant the run page turns into the viewer's own clock
     * (resources/js/deploy-log.js): `2026-09-12T22:16:42.123Z`. It is stored in UTC as
     * `Y-m-d H:i:s.v`, and the database may hand it back without the milliseconds.
     */
    public static function instant(string $loggedAt): string
    {
        return preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2}(?:\.\d+)?)/', $loggedAt, $parts) === 1
            ? "{$parts[1]}T{$parts[2]}Z"
            : $loggedAt;
    }

    /**
     * Terminal escape codes out, tabs kept, other control characters dropped.
     */
    public static function clean(string $line): string
    {
        $line = (string) preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]|\e\][^\a\e]*(?:\a|\e\\\\)|\e[()][A-Za-z0-9]|\e[@-Z\\\\-_]/', '', $line);

        return (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $line);
    }

    private function push(string $stream, string $line): void
    {
        $line = mb_strcut($this->redactor->redact(self::clean($line)), 0, self::MAX_LINE);

        $this->buffer[] = [
            'deployment_id' => $this->deployment->id,
            'seq' => ++$this->seq,
            'step_key' => $this->step,
            'stream' => $stream,
            'line' => $line,
            'logged_at' => now()->format('Y-m-d H:i:s.v'),
        ];
        $this->bytes += strlen($line);

        if (count($this->buffer) >= self::MAX_LINES || $this->bytes >= self::MAX_BYTES || microtime(true) - $this->flushedAt >= self::MAX_WAIT) {
            $this->flush();
        }
    }
}
