<?php

namespace App\Enums;

enum HookRunIn: string
{
    case Host = 'host';
    case ServiceRun = 'service_run';
    case ServiceExec = 'service_exec';

    public function label(): string
    {
        return match ($this) {
            self::Host => 'On the server',
            self::ServiceRun => 'One-off container',
            self::ServiceExec => 'Running container',
        };
    }

    public function needsService(): bool
    {
        return $this !== self::Host;
    }

    /**
     * Where the command runs, as shown next to it: "compose run --rm api".
     */
    public function describe(?string $service): string
    {
        return match ($this) {
            self::Host => 'in the release folder',
            self::ServiceRun => "compose run --rm {$service}",
            self::ServiceExec => "compose exec {$service}",
        };
    }
}
