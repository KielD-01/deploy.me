@php
    // Sign-in, setup and "confirm it's you" in the Console theme: one terminal window.
    $ui ??= \App\Support\Ui::preferences(auth()->user());
    $palette = \App\Support\Ui::palette('console');
@endphp
<!DOCTYPE html>
<html lang="en" data-ui="console" data-density="{{ $ui['density'] }}" data-theme="{{ $ui['mode'] === 'light' ? $palette['light'] : $palette['dark'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Sign in' }} · deploy.me</title>
    @vite(['resources/css/app.css', 'resources/js/guest.js'])
</head>
<body class="min-h-screen flex items-center justify-center p-6">
    <div class="w-full max-w-[420px] flex flex-col gap-4">
        <div class="text-[12px] t-mute"><span class="t-ok">●</span> deploy.me at <span class="text-base-content">{{ request()->getHost() }}</span></div>
        <section class="border border-line-strong bg-base-200">
            <div class="tmux-title"><b>$ deploy.me {{ \Illuminate\Support\Str::of($title ?? 'sign in')->lower()->replace(['’', "'"], '') }}</b></div>
            <div class="card-body gap-[16px] p-6">
                {{ $slot }}
            </div>
        </section>
    </div>
</body>
</html>
