<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * First run in the browser: while this machine has no account, anyone who can
 * open https://deployme.localhost (only local programs: the edge listens on
 * 127.0.0.1) creates the one account, then chooses a passkey or a password on
 * Settings → Account, as an enrollment link would have them do. Once an
 * account exists this page is gone; `make enroll` stays the way back in.
 */
class SetupController extends Controller
{
    public static function available(): bool
    {
        return (bool) config('deployme.web_setup') && ! User::query()->exists();
    }

    public function show(): View|RedirectResponse
    {
        if (! self::available()) {
            return redirect()->route('login');
        }

        return view('auth.setup', ['suggested' => (string) (config('deployme.host_user') ?: '')]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! self::available()) {
            return redirect()->route('login');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        try {
            // Two tabs, or two programs, racing for the one account: the first wins.
            $user = Cache::lock('deployme:web-setup', 10)->block(5, function () use ($data) {
                if (User::query()->exists()) {
                    return null;
                }

                // Nobody knows this password and password sign-in refuses it until
                // a real one is chosen (User::hasPassword()).
                return User::query()->create([
                    'name' => trim($data['name']),
                    'email' => Str::lower(trim($data['email'])),
                    'password' => Str::random(64),
                ]);
            });
        } catch (LockTimeoutException) {
            $user = null;
        }

        if ($user === null) {
            return redirect()->route('login')->withErrors(['enroll' => 'This machine already has an account. Sign in, or run `make enroll` for a one-time link.']);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();
        // Setting up the first passkey or password is a sensitive action; creating the account counts as the confirmation.
        $request->session()->passwordConfirmed();
        Audit::record(AuditAction::AccountCreated, ['subject' => $user->email, 'detail' => 'in the browser', 'user' => $user]);

        return redirect()->route('settings', ['enroll' => 1]);
    }
}
