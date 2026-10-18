<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * One-time sign-in links for registering a passkey: the first one, or a new
 * one after losing a device. Only someone with a shell on this machine can
 * create them (`php artisan deployme:enroll`), which is the same trust
 * boundary as the machine itself.
 */
final class EnrollmentLinks
{
    public const TTL_MINUTES = 15;

    public function create(User $user): string
    {
        $token = Str::random(48);

        Cache::put($this->key($token), $user->id, now()->addMinutes(self::TTL_MINUTES));

        return route('enroll', ['token' => $token]);
    }

    /**
     * The user the link belongs to, or null when it's unknown, expired or
     * already used. A link works exactly once.
     */
    public function consume(string $token): ?User
    {
        $key = $this->key($token);
        $id = Cache::get($key);

        if (! $id) {
            return null;
        }

        // pull() is a get and then a forget: two requests at once could both
        // get. Only the one that adds the "used" marker counts. add() is atomic
        // in every store deploy.me runs on (SET NX in Valkey, an insert that a
        // primary key refuses in the database store); forget() isn't a test:
        // the database store answers true for a key that was already gone.
        if (! Cache::add($key.':used', true, now()->addMinutes(self::TTL_MINUTES + 1))) {
            return null;
        }

        Cache::forget($key);

        return User::query()->find($id);
    }

    private function key(string $token): string
    {
        return 'deployme:enroll:'.hash('sha256', $token);
    }
}
