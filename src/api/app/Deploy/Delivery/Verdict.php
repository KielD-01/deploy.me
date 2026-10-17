<?php

namespace App\Deploy\Delivery;

/**
 * Whether a delivery strategy can ship the project right now, why, and which
 * commit it would ship. A CI run it waits for or went by rides along.
 */
final readonly class Verdict
{
    /**
     * @param  array<string, mixed>|null  $run  the CI run it's about (CiStatus::summary)
     */
    public function __construct(
        public VerdictState $state,
        public string $reason,
        public ?string $commit = null,
        public ?string $commitMessage = null,
        public ?array $run = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $run
     */
    public static function applicable(string $reason, ?string $commit = null, ?string $commitMessage = null, ?array $run = null): self
    {
        return new self(VerdictState::Applicable, $reason, $commit, $commitMessage, $run);
    }

    /**
     * @param  array<string, mixed>|null  $run
     */
    public static function pending(string $reason, ?string $commit = null, ?string $commitMessage = null, ?array $run = null): self
    {
        return new self(VerdictState::Pending, $reason, $commit, $commitMessage, $run);
    }

    public static function notApplicable(string $reason): self
    {
        return new self(VerdictState::NotApplicable, $reason);
    }

    /**
     * A check couldn't be made — GitHub or the registry didn't answer — so
     * whether the strategy applies is unknown. The next one is tried.
     */
    public static function error(string $reason): self
    {
        return new self(VerdictState::Error, $reason);
    }
}
