<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'server_id', 'captured_at', 'cpu_usage', 'cpu_mhz', 'load_1', 'load_5', 'load_15',
    'mem_total', 'mem_available', 'swap_total', 'swap_free', 'disks', 'stacks', 'uptime', 'ssh_latency_ms', 'processes',
])]
class ServerSnapshot extends Model
{
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
