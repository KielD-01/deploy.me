@php
    // The Console theme's layout: the app as a tmux session. The dashboard is the session
    // itself (panes you split); every other page opens as one pane, its window marked
    // in the status bar.
    $ui ??= \App\Support\Ui::preferences(auth()->user());
    $palette = \App\Support\Ui::palette('console');
    $windows = [
        ['dashboard', 'dashboard', 'ops'],
        ['deployments.index', 'deployments.*', 'runs'],
        ['servers.index', 'servers.*', 'servers'],
        ['projects.index', 'projects.*', 'projects'],
        ['audit', 'audit', 'audit'],
        ['settings', 'settings', 'settings'],
    ];
    $current = collect($windows)->search(fn ($window) => request()->routeIs($window[1]));
    $isSession = request()->routeIs('dashboard');
    $running = \App\Models\Deployment::query()->whereIn('status', \App\Enums\DeploymentStatus::ACTIVE)->count();
    $failing = \App\Models\Target::query()->active()->where('health', \App\Enums\TargetHealth::Unhealthy)->count();
@endphp
<!DOCTYPE html>
<html lang="en" data-ui="console" data-density="{{ $ui['density'] }}" @if ($ui['mode'] !== 'system') data-theme="{{ $palette[$ui['mode']] }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="reverb-key" content="{{ config('broadcasting.connections.reverb.key') }}">
    <title>{{ isset($title) ? $title.' · deploy.me' : 'deploy.me' }}</title>
    @if ($ui['mode'] === 'system')
        <script>
            document.documentElement.dataset.theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? @js($palette['dark']) : @js($palette['light']);
        </script>
    @endif
    <script>
        // Pages print times in this browser's timezone (App\Support\ViewerTimezone).
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
<body>
<div class="tmux" x-data="tmuxKeys(@js(collect($windows)->map(fn ($window) => route($window[0]))->values()))" x-on:keydown.window="key($event)">
    <div class="tmux-body">
        @if ($isSession)
            {{ $slot }}
        @else
            <section class="tmux-pane is-active" aria-label="{{ $title ?? 'Page' }}">
                <div class="tmux-title">
                    <b>{{ $current === false ? '·' : $current }} {{ $current === false ? 'page' : $windows[$current][2] }}</b>
                    <span>{{ $title ?? '' }}</span>
                    <span class="ml-auto">⌃b 0 back to the session · ⌃b ? keys</span>
                </div>
                <div class="tmux-content">
                    <main class="tmux-page">{{ $slot }}</main>
                </div>
            </section>
        @endif
    </div>

    <footer class="tmux-status" aria-label="Session">
        <span class="is-session">deploy.me</span>
        @foreach ($windows as $index => [$route, $pattern, $label])
            <a href="{{ route($route) }}" wire:navigate title="F{{ $index + 1 }} · ⌃b {{ $index }}" @class(['is-current' => $current === $index])>{{ $index }}:{{ $label }}{{ $current === $index ? '*' : '' }}</a>
        @endforeach
        <span class="ml-auto flex gap-3">
            @if ($update = \App\Support\Updates::available())<a href="{{ route('settings', ['tab' => 'updates']) }}" wire:navigate class="t-warn" title="deploy.me {{ $update['version'] }} is out: bin/update">↑ {{ $update['version'] }}</a>@endif
            @if ($failing > 0)<span class="t-err">● {{ $failing }} failing</span>@endif
            @if ($running > 0)<span class="t-run">● {{ $running }} running</span>@endif
            <span x-data="liveStatus" :class="live ? 't-ok' : 't-mute'" title="Realtime updates">● <span x-text="live ? 'live' : 'offline'">live</span></span>
            <button type="submit" form="logout-form" class="t-mute hover:text-base-content">logout</button>
        </span>
        <span class="is-clock" x-data="{ now: '' }" x-init="const tick = () => now = new Date().toLocaleString([], { hour: '2-digit', minute: '2-digit', day: '2-digit', month: 'short' }); tick(); setInterval(tick, 15000)" x-text="now"></span>
        <form id="logout-form" method="POST" action="{{ route('logout') }}" class="hidden">@csrf</form>
    </footer>

    <div class="tmux-overlay" x-show="overlay === 'tree'" x-cloak x-on:click.self="overlay = null" role="dialog" aria-label="Windows">
        <div>
            <div class="tmux-title"><b>⌃b w</b><span>choose a window · ↑↓ · enter · esc</span></div>
            <pre class="term">(0) <span class="t-b">deploy.me</span>: {{ count($windows) }} windows <span class="t-faint">(attached)</span>
@foreach ($windows as $index => [$route, $pattern, $label])
<a href="{{ route($route) }}" wire:navigate class="block {{ $current === $index ? 'term-pick' : '' }}">({{ $index + 1 }}) {{ $loop->last ? '└─>' : '├─>' }} <span class="{{ $current === $index ? 't-run' : '' }}">{{ $index }}: {{ $label }}{{ $current === $index ? '*' : '' }}</span></a>
@endforeach</pre>
        </div>
    </div>

    <div class="tmux-overlay" x-show="overlay === 'keys'" x-cloak x-on:click.self="overlay = null" role="dialog" aria-label="Keys">
        <div>
            <div class="tmux-title"><b>⌃b ?</b><span>keys · the prefix is ⌃b, like tmux · esc closes</span></div>
            <pre class="term"><span class="t-b">panes</span>    <span class="t-run">%</span> split right   <span class="t-run">"</span> split down   <span class="t-run">o</span> next   <span class="t-run">z</span> zoom   <span class="t-run">x</span> close
<span class="t-b">windows</span>  <span class="t-run">0-5</span> go   <span class="t-run">n p</span> next/prev   <span class="t-run">w</span> tree   <span class="t-mute">or</span> <span class="t-run">F1-F6</span> <span class="t-mute">without ⌃b</span>
<span class="t-b">layouts</span>  <span class="t-run">L</span> next saved layout (ops, deploy, watch)
<span class="t-b">shell</span>    <span class="t-run">/</span> focus the shell · type <span class="t-b">help</span> for its commands
<span class="t-b">mouse</span>    <span class="t-mute">drag a border to resize · click a pane to focus · everything works without the keys too</span></pre>
        </div>
    </div>
</div>
</body>
</html>
