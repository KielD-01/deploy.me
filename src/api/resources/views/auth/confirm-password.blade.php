<x-layouts.guest title="Confirm it’s you">
    <div class="flex flex-col gap-1">
        <h1 class="text-[22px] leading-7 font-semibold">Confirm it’s you</h1>
        <p class="text-muted">This changes how deploy.me reaches your servers or signs you in.</p>
    </div>

    @php($user = auth()->user())

    @if ($user?->passkeys()->exists())
        <button type="button" data-passkey-confirm data-intended="{{ session('url.intended', route('dashboard')) }}" class="btn btn-primary w-full">
            <x-icon name="fingerprint" /> <span data-label>Confirm with a passkey</span>
        </button>
        <p data-passkey-error class="text-error text-[13px] empty:hidden" role="status"></p>
    @endif

    @if ($user?->hasPassword())
        @if ($user->passkeys()->exists())
            <div class="divider text-xs text-muted my-0">or</div>
        @endif
        <form method="POST" action="{{ route('password.confirm.store') }}" class="flex flex-col gap-3">
            @csrf
            <label class="flex flex-col gap-1.5">
                <span class="text-[13px] font-medium">Password</span>
                <input type="password" name="password" autocomplete="current-password" required @if (! $user->passkeys()->exists()) autofocus @endif class="input w-full @error('password') input-error @enderror">
            </label>
            @error('password')
                <p class="text-error text-[13px]" role="alert">{{ $message }}</p>
            @enderror
            <button type="submit" class="btn w-full"><x-icon name="key" /> Confirm with password</button>
        </form>
    @endif

    @if (! $user?->passkeys()->exists() && ! $user?->hasPassword())
        {{-- Signed in by an enrollment link left unfinished: nothing to confirm with yet. --}}
        <div role="alert" class="alert alert-warning text-[13px] items-start">
            <span>You haven’t set up a passkey or a password yet. Run <span class="mono">make enroll</span> in the deploy.me folder and open the link it prints: it signs you in, lets you set one up, and counts as this confirmation.</span>
        </div>
    @endif

    <a href="{{ route('dashboard') }}" class="link text-[13px] self-start">Cancel</a>
</x-layouts.guest>
