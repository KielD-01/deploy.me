<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
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
        // Counted under the user's row lock, which removing the password takes
        // too: two removals at once can't each see the other's way in left.
        DB::transaction(function () use ($user, $passkey): void {
            $locked = User::query()->whereKey($user->getAuthIdentifier())->lockForUpdate()->first();

            if ($locked !== null && $locked->waysIn() <= 1) {
                throw ValidationException::withMessages([
                    'passkey' => 'That’s your only way in. Set a password or add another passkey first.',
                ]);
            }

            parent::__invoke($user, $passkey);
        });
    }
}
