<?php

namespace App\Mcp\Tools;

use App\Actions\Deployments\CancelDeployment as Cancel;
use App\Mcp\Describe;
use App\Mcp\Find;
use App\Mcp\Tool;
use App\Mcp\ToolFailed;

class CancelDeployment extends Tool
{
    public function name(): string
    {
        return 'cancel_deployment';
    }

    public function description(): string
    {
        return 'Cancels a queued or running run. One that hasn’t started stops at once; a running one stops at its next check, and its cleanup (switching back, unlocking) still runs.';
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

    public function deploys(): bool
    {
        return true;
    }

    public function call(array $arguments): array
    {
        $run = Find::deployment($this->requiredString($arguments, 'run'));

        if (! $run->isActive()) {
            throw new ToolFailed("Run #{$run->id} has already ended ({$run->status->label()}).");
        }

        app(Cancel::class)($run);

        return Describe::deployment($run->refresh()->load(['project', 'server']));
    }
}
