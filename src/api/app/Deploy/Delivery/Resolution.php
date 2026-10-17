<?php

namespace App\Deploy\Delivery;

use App\Enums\DeliveryStrategyKey;

/**
 * The strategy a deploy uses (or the one it waits for), and the trace of how
 * each one in the priority list answered: the run page's "why this strategy".
 */
final readonly class Resolution
{
    /**
     * @param  list<array{key: string, label: string, state: string, reason: string, run?: array<string, mixed>}>  $trace
     */
    public function __construct(
        public ?DeliveryStrategyKey $strategy,
        public ?Verdict $verdict,
        public array $trace,
        public ?DeliveryStrategyKey $pendingStrategy = null,
        public ?Verdict $pending = null,
    ) {}

    public function found(): bool
    {
        return $this->strategy !== null;
    }

    /**
     * A strategy ahead of the rest is waiting for CI, and the project waits for it.
     */
    public function waiting(): bool
    {
        return $this->strategy === null && $this->pendingStrategy !== null;
    }

    /**
     * What "deploy now" would use instead of waiting: the first strategy after
     * the pending one that applies.
     */
    public function fallback(): ?DeliveryStrategyKey
    {
        foreach ($this->trace as $entry) {
            if ($entry['state'] === VerdictState::Applicable->value) {
                return DeliveryStrategyKey::from($entry['key']);
            }
        }

        return null;
    }

    /**
     * The strategies whose checks couldn't be made: a request failed, so what
     * they'd have answered is unknown.
     *
     * @return list<array{key: string, label: string, state: string, reason: string, run?: array<string, mixed>}>
     */
    public function errors(): array
    {
        return array_values(array_filter($this->trace, fn (array $entry) => $entry['state'] === VerdictState::Error->value));
    }

    /**
     * Why nothing can deliver the project, in a sentence or two.
     */
    public function reason(): string
    {
        $tried = array_values(array_filter($this->trace, fn (array $entry) => $entry['state'] !== VerdictState::Off->value));

        if ($tried === []) {
            return 'Every way to deliver releases is switched off in the project’s settings.';
        }

        return ($this->errors() === [] ? 'Nothing can deliver this project right now. ' : 'Nothing can deliver this project right now, and some checks couldn’t be made. ')
            .implode(' ', array_map(
                fn (array $entry) => $entry['label'].': '.rtrim($entry['reason'], '.').'.',
                $tried,
            ));
    }
}
