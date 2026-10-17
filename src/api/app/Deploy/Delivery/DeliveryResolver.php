<?php

namespace App\Deploy\Delivery;

use App\Deploy\Delivery\Strategies\GhcrImage;
use App\Deploy\Delivery\Strategies\PushArchive;
use App\Deploy\Delivery\Strategies\PushRsync;
use App\Deploy\Delivery\Strategies\ServerGit;
use App\Enums\DeliveryStrategyKey;
use App\Models\Project;

/**
 * Walks the project's priority list: the first switched-on strategy that
 * applies delivers the release. One that's still waiting for CI holds the
 * deploy, when the project waits for CI; the ones after it still say whether
 * they'd do (that's what "deploy now" falls back to). Every answer goes into
 * the trace.
 *
 * A strategy whose check couldn't be made — GitHub or the registry didn't
 * answer — is passed over like one that doesn't apply, but the trace keeps it
 * apart, so nobody reads a failed request as "your project doesn't qualify".
 */
class DeliveryResolver
{
    /** @var array<string, DeliveryStrategy> */
    private array $strategies = [];

    public function __construct(GhcrImage $ghcr, PushArchive $archive, PushRsync $rsync, ServerGit $serverGit)
    {
        foreach ([$ghcr, $archive, $rsync, $serverGit] as $strategy) {
            $this->strategies[$strategy->key()->value] = $strategy;
        }
    }

    /**
     * @param  DeliveryStrategyKey|null  $only  a strategy picked by hand: the only one tried
     */
    public function resolve(Project $project, ?string $commit = null, ?DeliveryStrategyKey $only = null): Resolution
    {
        $trace = [];
        $winner = null;
        $verdict = null;
        $pendingKey = null;
        $pending = null;
        $fallbackFound = false;

        foreach ($project->strategies() as ['key' => $key, 'enabled' => $enabled]) {
            $entry = fn (VerdictState $state, string $reason, ?array $run = null) => array_filter(
                ['key' => $key->value, 'label' => $key->label(), 'state' => $state->value, 'reason' => $reason, 'run' => $run],
                fn ($value) => $value !== null,
            );

            if ($only !== null && $key !== $only) {
                $trace[] = $entry(VerdictState::NotNeeded, "You picked {$only->label()}.");

                continue;
            }

            if (! $enabled && $only === null) {
                $trace[] = $entry(VerdictState::Off, 'Switched off.');

                continue;
            }

            if ($winner !== null || $fallbackFound) {
                $trace[] = $entry(VerdictState::NotNeeded, ($winner ?? $pendingKey)->label().' comes first.');

                continue;
            }

            $answer = $this->strategies[$key->value]->evaluate($project, $commit);
            $trace[] = $entry($answer->state, $answer->reason, $answer->run);

            if ($answer->state === VerdictState::Applicable) {
                if ($pendingKey === null) {
                    $winner = $key;
                    $verdict = $answer;
                } else {
                    $fallbackFound = true;
                }
            } elseif ($answer->state === VerdictState::Pending && $pendingKey === null && $project->wait_for_ci && $only === null) {
                $pendingKey = $key;
                $pending = $answer;
            }
        }

        return new Resolution($winner, $verdict, $trace, $winner === null ? $pendingKey : null, $winner === null ? $pending : null);
    }
}
