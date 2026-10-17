<x-layouts.guest title="Authenticator code">
    <div class="flex flex-col gap-1">
        <h1 class="text-[22px] leading-7 font-semibold">Authenticator code</h1>
        <p class="text-muted">Enter the 6-digit code your authenticator app shows for deploy.me.</p>
    </div>

    <form method="POST" action="{{ route('two-factor.login.store') }}" class="flex flex-col gap-3">
        @csrf
        <label class="flex flex-col gap-1.5">
            <span class="text-[13px] font-medium">Code</span>
            <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" autofocus class="input w-full font-mono tracking-widest">
        </label>
        <details class="text-[13px]" @if (old('recovery_code')) open @endif>
            <summary class="link cursor-pointer">Lost the app? Use a recovery code</summary>
            <label class="flex flex-col gap-1.5 mt-2">
                <span class="font-medium">Recovery code</span>
                <input type="text" name="recovery_code" autocomplete="off" class="input w-full font-mono">
            </label>
        </details>
        @if ($errors->any())
            <p class="text-error text-[13px]" role="alert">{{ $errors->first() }}</p>
        @endif
        <button type="submit" class="btn btn-primary w-full">Continue</button>
    </form>

    <a href="{{ route('login') }}" class="link text-[13px] self-start">Back to sign in</a>
</x-layouts.guest>
