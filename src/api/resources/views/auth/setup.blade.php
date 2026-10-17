<x-layouts.guest title="Create your account">
    <div class="flex flex-col gap-1">
        <h1 class="text-[22px] leading-7 font-semibold">Create your account</h1>
        <p class="text-muted">deploy.me has one account per machine, and this one has none yet. Next you’ll choose a passkey or a password.</p>
    </div>

    <form method="POST" action="{{ route('setup.store') }}" class="flex flex-col gap-3">
        @csrf
        <label class="flex flex-col gap-1.5">
            <span class="text-[13px] font-medium">Name</span>
            <input type="text" name="name" value="{{ old('name', $suggested) }}" autocomplete="name" required maxlength="255" autofocus class="input w-full @error('name') input-error @enderror">
        </label>
        <label class="flex flex-col gap-1.5">
            <span class="text-[13px] font-medium">Email</span>
            <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required maxlength="255" class="input w-full @error('email') input-error @enderror">
            <span class="text-xs text-muted">What your passkey manager shows, and what you sign in with. Nothing is sent to it.</span>
        </label>
        @if ($errors->any())
            <p class="text-error text-[13px]" role="alert">{{ $errors->first() }}</p>
        @endif
        <button type="submit" class="btn btn-primary w-full">Continue</button>
    </form>

    <a href="{{ route('login') }}" class="link text-[13px] self-start">Back</a>
</x-layouts.guest>
