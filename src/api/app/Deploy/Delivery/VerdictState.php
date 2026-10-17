<?php

namespace App\Deploy\Delivery;

enum VerdictState: string
{
    case Applicable = 'applicable';
    // It will apply, once CI finishes.
    case Pending = 'pending';
    case NotApplicable = 'not_applicable';
    // A check couldn't be made: GitHub or the registry didn't answer. It says
    // nothing about whether the strategy applies, so the next one is tried.
    case Error = 'error';
    // Switched off in the project's settings.
    case Off = 'off';
    // An earlier strategy already applies.
    case NotNeeded = 'not_needed';

    public function label(): string
    {
        return match ($this) {
            self::Applicable => 'Applies',
            self::Pending => 'Waiting',
            self::NotApplicable => 'Doesn’t apply',
            self::Error => 'Couldn’t check',
            self::Off => 'Off',
            self::NotNeeded => 'Not needed',
        };
    }

    /**
     * The same wording as a sentence about one strategy: "ghcr.io applies".
     */
    public function sentence(): string
    {
        return match ($this) {
            self::Applicable => 'applies',
            self::Pending => 'is pending',
            self::NotApplicable => 'doesn’t apply',
            self::Error => 'couldn’t be checked',
            self::Off => 'is off',
            self::NotNeeded => 'isn’t needed',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Applicable => 'check-circle',
            self::Pending => 'clock',
            self::NotApplicable => 'x-circle',
            self::Error => 'alert',
            default => 'skip',
        };
    }

    public function toneClass(): string
    {
        return match ($this) {
            self::Applicable => 'text-success',
            self::Pending => 'text-info',
            self::Error => 'text-warning',
            default => 'text-muted',
        };
    }

    /**
     * States that say nothing was decided about the strategy itself, so the
     * reason behind them isn't worth showing.
     */
    public function quiet(): bool
    {
        return $this === self::Off || $this === self::NotNeeded;
    }
}
