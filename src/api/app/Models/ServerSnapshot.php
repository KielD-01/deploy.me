<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'server_id', 'captured_at', 'cpu_usage', 'cpu_mhz', 'load_1', 'load_5', 'load_15',
    'mem_total', 'mem_available', 'swap_total', 'swap_free', 'disks', 'stacks', 'uptime', 'ssh_latency_ms', 'processes',
])]
class ServerSnapshot extends Model
{
    /** Days a snapshot keeps its busiest-process lists (about 2 KB each); the readings stay as long as the snapshots. */
    public const PROCESS_DAYS = 2;

    /** The width of a point on a chart, and of a step in the picker of earlier process lists. */
    public const BUCKET = '5 minutes';

    // The bucket a reading falls in, as SQL: all constants, so no bindings to tell apart in a GROUP BY.
    private const BIN = "date_bin('5 minutes', captured_at, timestamp '2000-01-01 00:00:00')";

    // The same on SQLite (the slim flavour), which keeps a timestamp as text:
    // whole seconds since the epoch, down to a multiple of 300, back to the same
    // text. 2000-01-01 is itself a multiple of 300 s, so the buckets match.
    private const SQLITE_BIN = "datetime((cast(strftime('%s', captured_at) as integer) / 300) * 300, 'unixepoch')";

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'cpu_usage' => 'float',
            'load_1' => 'float',
            'load_5' => 'float',
            'load_15' => 'float',
            'mem_total' => 'integer',
            'mem_available' => 'integer',
            'disks' => 'array',
            'stacks' => 'array',
            'processes' => 'array',
        ];
    }

    /**
     * The readings since a moment, averaged over five-minute buckets, per
     * server: at most 288 points for a day instead of a row per probe (1,440
     * at a one-minute interval) to hydrate and draw.
     *
     * @param  array<int, int>  $serverIds
     * @return Collection<int, Collection<int, object{at: Carbon, cpu: ?float, mem: ?float}>> by server id, oldest first
     */
    public static function history(array $serverIds, CarbonInterface $since): Collection
    {
        if ($serverIds === []) {
            return collect();
        }

        return DB::table('server_snapshots')
            ->selectRaw('server_id, '.self::bin().' as bucket, avg(cpu_usage) as cpu, avg(100.0 * (1 - '.self::float('mem_available').' / nullif(mem_total, 0))) as mem')
            ->whereIn('server_id', $serverIds)
            ->where('captured_at', '>=', $since)
            ->groupBy('server_id', 'bucket')
            ->orderBy('bucket')
            ->get()
            ->groupBy('server_id')
            ->map(fn (Collection $rows) => $rows->map(fn (object $row) => (object) [
                'at' => Carbon::parse($row->bucket),
                'cpu' => $row->cpu !== null ? round((float) $row->cpu, 1) : null,
                'mem' => $row->mem !== null ? round((float) $row->mem, 1) : null,
            ])->values());
    }

    /**
     * The probes a server's process picker offers: the last one in each
     * five-minute step of the last day that kept its process lists.
     *
     * @return Collection<int, CarbonInterface> captured_at by snapshot id, newest first
     */
    public static function processTimes(int $serverId, CarbonInterface $since): Collection
    {
        $last = DB::table('server_snapshots')
            ->selectRaw('max(id)')
            ->where('server_id', $serverId)
            ->whereNotNull('processes')
            ->where('captured_at', '>=', $since)
            ->groupByRaw(self::bin());

        return self::query()->whereIn('id', $last)->orderByDesc('captured_at')->pluck('captured_at', 'id');
    }

    /** The five-minute bucket expression for the database in use. */
    private static function bin(): string
    {
        return DB::getDriverName() === 'sqlite' ? self::SQLITE_BIN : self::BIN;
    }

    /** A column as a floating-point number, so integer division can't round it away. */
    private static function float(string $column): string
    {
        return DB::getDriverName() === 'sqlite' ? "cast({$column} as real)" : "{$column}::float8";
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function memoryUsedPercent(): ?float
    {
        return $this->mem_total ? round(100 * (1 - $this->mem_available / $this->mem_total), 1) : null;
    }

    /**
     * The filesystem a path lives on: the mount with the longest matching prefix.
     *
     * @return array{source: string, fstype: string, size: int, used: int, available: int, mount: string}|null
     */
    public function diskFor(string $path): ?array
    {
        $best = null;

        foreach ($this->disks ?? [] as $disk) {
            if (str_starts_with(rtrim($path, '/').'/', rtrim($disk['mount'], '/').'/') && ($best === null || strlen($disk['mount']) > strlen($best['mount']))) {
                $best = $disk;
            }
        }

        return $best;
    }

    public static function diskUsedPercent(?array $disk): ?float
    {
        return $disk && $disk['size'] > 0 ? round(100 * $disk['used'] / $disk['size'], 1) : null;
    }
}
