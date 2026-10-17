<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Sign in' }} · deploy.me</title>
    <script>
        try {
            const theme = localStorage.getItem('deployme-theme');
            if (theme) document.documentElement.dataset.theme = theme;
        } catch {}
    </script>
    @vite(['resources/css/app.css', 'resources/js/guest.js'])
</head>
<body class="min-h-screen flex items-center justify-center p-6">
    <section class="card bg-base-100 border border-line w-full max-w-[380px]">
        <div class="card-body gap-[18px] p-7">
            <div class="flex items-center gap-2.5">
                <x-logo />
                <span class="text-base font-semibold">deploy.me</span>
            </div>
            {{ $slot }}
        </div>
    </section>
</body>
</html>
