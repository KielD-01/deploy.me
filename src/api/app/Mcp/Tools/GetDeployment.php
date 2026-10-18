<?php

namespace App\Mcp\Tools;

use App\Mcp\Describe;
use App\Mcp\Find;
use App\Mcp\Tool;
use App\Models\DeploymentStep;

class GetDeployment extends Tool
{
    // Log lines that come with the run; get_deployment_log reads the rest.
    public const TAIL = 40;

    public function name(): string
    {
        return 'get_deployment';
    }

    public function description(): string
    {
        return 'One run: its steps and how each ended, the delivery strategy and why it was picked, and the last '.self::TAIL.' lines of its (redacted) log. Use it to explain why a deploy failed.';
    }

    public function properties(): array
    {
        return [
            'run' => ['type' => 'string', 'description' => 'The run number, like 42 or #42.'],
        ];
    }

    public function required(): array
    {
        return ['run'];
    }

    public function call(array $arguments): array
    {
        $run = Find::deployment($this->requiredString($arguments, 'run'));
        $run->load(['project', 'server', 'steps']);

        $tail = $run->logLines(max(0, $run->log_lines - self::TAIL), self::TAIL);

        return [
            ...Describe::deployment($run),
            // An action run's secret inputs (encrypted until it starts) never leave.
            'options' => array_diff_key($run->options ?? [], ['secrets' => true]),
            'strategy_trace' => $run->strategy_trace ?? [],
            'retry_of' => $run->retry_of_id,
            'steps' => $run->steps->map(fn (DeploymentStep $step) => Describe::step($step))->all(),
            'log_lines' => $run->log_lines,
            'log_tail' => $tail->map(fn (object $line) => ($line->step_key !== null ? "[{$line->step_key}] " : '').$line->line)->all(),
        ];
    }
}
