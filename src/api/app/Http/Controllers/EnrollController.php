<?php

namespace App\Http\Controllers;

use App\Auth\EnrollmentLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnrollController
{
    public function __invoke(Request $request, string $token, EnrollmentLinks $links): RedirectResponse
    {
        $user = $links->consume($token);

        if ($user === null) {
            return redirect()->route('login')->withErrors([
                'enroll' => 'That link has expired or was already used. Run `make enroll` for a new one.',
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        // Registering a passkey is a sensitive action; the link counts as the confirmation.
        $request->session()->passwordConfirmed();

        return redirect()->route('settings', ['enroll' => 1]);
    }
}
