<?php

namespace App\Providers;

use App\Auth\DeletePasskeyKeepingAWayIn;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Actions\DeletePasskey;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DeletePasskey::class, DeletePasskeyKeepingAWayIn::class);
    }

    /**
     * Bootstrap any application services.
     *
     * A passkey signs in on its own. A password signs in only once its owner
     * chose one (User::hasPassword()); the random value the column holds until
     * then is refused, and so is any other account. With an authenticator set
     * up, Fortify then asks for its code.
     */
    public function boot(): void
    {
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));

        Fortify::authenticateUsing(function (Request $request) {
            $user = User::query()->where('email', Str::lower((string) $request->input('email')))->first();

            return $user?->hasPassword() && Hash::check((string) $request->input('password'), $user->password) ? $user : null;
        });

        Fortify::confirmPasswordsUsing(fn (User $user, ?string $password) => $user->hasPassword() && Hash::check((string) $password, $user->password));

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by($request->session()->get('login.id').'|'.$request->ip()));

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(($credentialId ?: $request->session()->getId()).'|'.$request->ip());
        });
    }
}
