<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Passkey;

/**
 * Removing a passkey, but never the last way in: without another passkey or a
 * password, only `make enroll` could get you back in.
 */
class DeletePasskeyKeepingAWayIn extends DeletePasskey
{
    public function __invoke(Authenticatable $user, Passkey $passkey): void
    {
        if ($user instanceof User && $user->waysIn() <= 1) {
            throw ValidationException::withMessages([
                'passkey' => 'That’s your only way in. Set a password or add another passkey first.',
            ]);
        }

        parent::__invoke($user, $passkey);
    }
}
