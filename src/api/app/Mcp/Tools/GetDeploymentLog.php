<?php

namespace App\Mcp\Tools;

use App\Deploy\Logging\LogArchive;
use App\Mcp\Find;
use App\Mcp\Tool;

class GetDeploymentLog extends Tool
{
    public const MAX = 1000;

    public function name(): string
    {
        return 'get_deployment_log';
    }

    public function description(): string
    {
        return 'A run’s log, oldest first, already redacted (secrets never reach it). Page through a long one with “after”: pass the last seq you got.';
    }

    public function properties(): array
    {
        return [
            'run' => ['type' => 'string', 'description' => 'The run number, like 42 or #42.'],
            'after' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'Only lines after this seq.'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX, 'default' => 200],
            'step' => ['type' => 'string', 'description' => 'Only this step’s lines (a step key from get_deployment).'],
        ];
    }

    public function required(): array
    {
        return ['run'];
    }

    public function call(array $arguments): array
    {
        $run = Find::deployment($this->requiredString($arguments, 'run'));
        $step = $this->string($arguments, 'step');
        $limit = $this->int($arguments, 'limit', 200, max: self::MAX);

        // The step is picked in the query: other steps' lines before it can't hide it.
        $lines = $run->logLines($this->int($arguments, 'after', 0, min: 0), $limit, $step)->values();

        return [
            'run' => $run->id,
            'status' => $run->status->value,
            'total_lines' => $run->log_lines,
            'lines' => $lines->map(fn (object $line) => ['seq' => (int) $line->seq, 'step' => $line->step_key, 'stream' => $line->stream, 'line' => $line->line])->all(),
            'next_after' => $lines->isNotEmpty() ? (int) $lines->last()->seq : null,
            // A finished run's lines leave the database for an archive after a while.
            'archived' => $lines->isEmpty() && $run->logLines(0, 1)->isEmpty() && LogArchive::exists($run->id),
            'download' => route('deployments.log', $run),
        ];
    }
}
