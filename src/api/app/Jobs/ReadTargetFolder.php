<?php

namespace App\Jobs;

use App\Actions\Targets\ReadFolder;
use App\Models\Target;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reads a target's folder for converting it, on the deploys worker: the
 * repositories are there, and a comparison can take a while.
 */
class ReadTargetFolder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // Below the deploys worker's --timeout=3600.
    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(public int $targetId, public bool $compare = true)
    {
        $this->onConnection('redis-deploys')->onQueue('deploys');
    }

    public function uniqueId(): string
    {
        return (string) $this->targetId;
    }

    /**
     * Marks the target as being read, for the pages, and queues the read.
     */
    public static function start(Target $target, bool $compare = true): void
    {
        $target->forceFill(['folder_scan' => [...($target->folder_scan ?? []), 'reading' => now()->toIso8601String(), 'error' => null]])->save();

        self::dispatch($target->id, $compare);
    }

    public function handle(ReadFolder $read): void
    {
        $target = Target::query()->with(['project', 'server'])->find($this->targetId);

        // Only a read someone asked for: start() marks the target first.
        if ($target !== null && $target->archived_at === null && is_string($target->folder_scan['reading'] ?? null)) {
            $read($target, $this->compare);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $target = Target::query()->find($this->targetId);

        $target?->forceFill(['folder_scan' => [
            ...($target->folder_scan ?? []),
            'reading' => null,
            'error' => 'Reading the folder stopped: '.($exception?->getMessage() ?: 'no reason given'),
        ]])->save();
    }
}
