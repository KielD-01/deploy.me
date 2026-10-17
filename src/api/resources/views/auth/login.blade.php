<x-layouts.guest title="Sign in">
    <div class="flex flex-col gap-1">
        <h1 class="text-[22px] leading-7 font-semibold">Sign in</h1>
        <p class="text-muted">Use the passkey on this device, like Touch ID.</p>
    </div>

    @error('enroll')
        <div role="alert" class="alert alert-soft alert-warning text-[13px]"><x-icon name="alert" /> {{ $message }}</div>
    @enderror

    <button type="button" data-passkey-login class="btn btn-primary w-full">
        <x-icon name="fingerprint" /> <span data-label>Sign in with a passkey</span>
    </button>
    <p data-passkey-error class="text-error text-[13px] empty:hidden" role="status"></p>

    <p class="text-xs text-muted">
        First time here, or lost your passkey? Run <code class="font-mono">make enroll</code> in a terminal for a one-time link.
    </p>
</x-layouts.guest>
