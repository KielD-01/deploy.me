<x-layouts.guest title="Confirm it’s you">
    <div class="flex flex-col gap-1">
        <h1 class="text-[22px] leading-7 font-semibold">Confirm it’s you</h1>
        <p class="text-muted">This changes how deploy.me reaches your servers or signs you in. Confirm with your passkey to continue.</p>
    </div>

    @if (auth()->user()?->passkeys()->exists())
        <button type="button" data-passkey-confirm data-intended="{{ session('url.intended', route('dashboard')) }}" class="btn btn-primary w-full">
            <x-icon name="fingerprint" /> <span data-label>Confirm with a passkey</span>
        </button>
        <p data-passkey-error class="text-error text-[13px] empty:hidden" role="status"></p>
    @else
        {{-- Signed in without a passkey (an enrollment link left unfinished): nothing to confirm with yet. --}}
        <div role="alert" class="alert alert-warning text-[13px] items-start">
            <span>You haven’t registered a passkey yet. Run <span class="mono">make enroll</span> in the deploy.me folder and open the link it prints: it signs you in, lets you register one, and counts as this confirmation.</span>
        </div>
    @endif

    <a href="{{ route('dashboard') }}" class="link text-[13px] self-start">Cancel</a>
</x-layouts.guest>
