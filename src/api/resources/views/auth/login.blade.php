<x-layouts.guest title="Sign in">
    <div class="flex flex-col gap-1">
        <h1 class="text-[22px] leading-7 font-semibold">Sign in</h1>
        <p class="text-muted">Use the passkey on this device, like Touch ID, or your password.</p>
    </div>

    @error('enroll')
        <div role="alert" class="alert alert-soft alert-warning text-[13px]"><x-icon name="alert" /> {{ $message }}</div>
    @enderror

    <button type="button" data-passkey-login class="btn btn-primary w-full">
        <x-icon name="fingerprint" /> <span data-label>Sign in with a passkey</span>
    </button>
    <p data-passkey-error class="text-error text-[13px] empty:hidden" role="status"></p>

    <div class="divider text-xs text-muted my-0">or</div>

    {{-- Only for an account whose owner chose a password (Settings → Account). --}}
    <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-3">
        @csrf
        <label class="flex flex-col gap-1.5">
            <span class="text-[13px] font-medium">Email</span>
            <input type="email" name="email" value="{{ old('email') }}" autocomplete="username" required class="input w-full @error('email') input-error @enderror">
        </label>
        <label class="flex flex-col gap-1.5">
            <span class="text-[13px] font-medium">Password</span>
            <input type="password" name="password" autocomplete="current-password" required class="input w-full">
        </label>
        @error('email')
            <p class="text-error text-[13px]" role="alert">{{ $message }}</p>
        @enderror
        <label class="flex items-center gap-2 text-[13px]">
            <input type="checkbox" name="remember" value="1" class="checkbox checkbox-sm" checked> Stay signed in on this device
        </label>
        <button type="submit" class="btn w-full"><x-icon name="key" /> Sign in with password</button>
    </form>

    <p class="text-xs text-muted">
        First time here, or lost your way in? Run <code class="font-mono">make enroll</code> in a terminal for a one-time link.
    </p>
</x-layouts.guest>
