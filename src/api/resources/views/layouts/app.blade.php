@php
    $ui ??= \App\Support\Ui::preferences(auth()->user());
    $palette = \App\Support\Ui::palette('classic');
@endphp
<!DOCTYPE html>
<html lang="en" data-ui="classic" data-density="{{ $ui['density'] }}" @if ($ui['mode'] !== 'system') data-theme="{{ $palette[$ui['mode']] }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="reverb-key" content="{{ config('broadcasting.connections.reverb.key') }}">
    <title>{{ isset($title) ? $title.' · deploy.me' : 'deploy.me' }}</title>
    <script>
        // With the mode on "system" (Settings → Appearance), the header's toggle can still pick
        // one for this browser; a saved light or dark is applied above, before first paint.
        try {
            const theme = localStorage.getItem('deployme-theme');
            if (theme && @js($ui['mode'] === 'system')) document.documentElement.dataset.theme = theme;
        } catch {}
    </script>
    <script>
        // Pages print times in this browser's timezone (App\Support\ViewerTimezone): tell the
        // server which one. The first page that sets the cookie was printed in UTC, so it
        // loads once more; at most once a session, in case cookies don't stick.
        try {
            const zone = encodeURIComponent(Intl.DateTimeFormat().resolvedOptions().timeZone);
            const known = () => document.cookie.split('; ').includes('tz=' + zone);
            if (!known()) {
                document.cookie = 'tz=' + zone + '; path=/; max-age=31536000; SameSite=Lax; Secure';
                if (known() && !sessionStorage.getItem('deployme-tz-reloaded')) {
                    sessionStorage.setItem('deployme-tz-reloaded', '1');
                    location.reload();
                }
            }
        } catch {}
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen">
    @php
        $nav = [
            ['dashboard', 'dashboard', 'Dashboard', 'activity'],
            ['servers.index', 'servers.*', 'Servers', 'server'],
            ['projects.index', 'projects.*', 'Projects', 'repo'],
            ['deployments.index', 'deployments.*', 'Deployments', 'deploy'],
            ['audit', 'audit', 'Audit', 'shield'],
            ['settings', 'settings', 'Settings', 'sliders'],
        ];
    @endphp

    <header class="h-14 flex items-center gap-7 px-8 bg-base-100 border-b border-line">
        <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2.5 font-semibold text-[15px] tracking-tight">
            <x-logo /> deploy.me
        </a>

        <nav class="flex gap-0.5">
            @foreach ($nav as [$route, $pattern, $label, $icon])
                <a href="{{ route($route) }}" wire:navigate
                   @class([
                       'flex items-center gap-1.5 h-8 px-2.5 rounded-md font-medium',
                       'bg-sunk text-base-content' => request()->routeIs($pattern),
                       'text-muted hover:text-base-content' => ! request()->routeIs($pattern),
                   ])>
                    <x-icon :name="$icon" /> {{ $label }}
                </a>
            @endforeach
        </nav>

        <div class="ml-auto flex items-center gap-2">
            <span x-data="liveStatus" class="badge badge-sm gap-1.5" :class="live ? 'badge-soft badge-success' : 'badge-ghost'" title="Realtime updates">
                <span class="status" :class="live ? 'status-success' : 'status-neutral'"></span>
                <span x-text="label">Connecting</span>
            </span>

            <button type="button" x-data="themeToggle" x-on:click="toggle" class="btn btn-ghost btn-sm btn-square text-muted" aria-label="Switch light or dark theme">
                <x-icon name="moon" x-show="! dark" />
                <x-icon name="sun" x-show="dark" x-cloak />
            </button>

            <div class="dropdown dropdown-end">
                <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-circle text-muted" aria-label="Account">
                    <x-icon name="user" />
                </div>
                <ul tabindex="0" class="dropdown-content menu bg-base-100 rounded-box border border-line w-56 p-1 mt-2 z-20">
                    <li class="menu-title truncate">{{ auth()->user()?->email }}</li>
                    <li><a href="{{ route('settings') }}" wire:navigate><x-icon name="sliders" /> Settings</a></li>
                    @if ($update = \App\Support\Updates::available())
                        <li><a href="{{ route('settings', ['tab' => 'updates']) }}" wire:navigate><x-icon name="upload" /> deploy.me {{ $update['version'] }} is out</a></li>
                    @endif
                    <li><button type="submit" form="logout-form"><x-icon name="logout" /> Sign out</button></li>
                </ul>
            </div>
            <form id="logout-form" method="POST" action="{{ route('logout') }}" class="hidden">@csrf</form>
        </div>
    </header>

    <main class="px-8 pt-6 pb-12 flex flex-col gap-5">
        {{ $slot }}
    </main>
</body>
</html>
