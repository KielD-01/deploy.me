<?php

namespace App\Livewire\Concerns;

/**
 * Sensitive Livewire actions ask for a fresh passkey confirmation, the same
 * one the password.confirm middleware asks for on routes.
 */
trait ConfirmsIdentity
{
    protected function identityConfirmed(): bool
    {
        $confirmedAt = (int) session('auth.password_confirmed_at', 0);

        return time() - $confirmedAt < (int) config('auth.password_timeout', 10800);
    }

    /**
     * True when you confirmed recently. Otherwise sends you to confirm with a
     * passkey, then back to $returnTo, and returns false so the action stops.
     */
    protected function confirmIdentity(string $returnTo): bool
    {
        if ($this->identityConfirmed()) {
            return true;
        }

        session()->put('url.intended', $returnTo);
        $this->redirectRoute('password.confirm');

        return false;
    }
}
