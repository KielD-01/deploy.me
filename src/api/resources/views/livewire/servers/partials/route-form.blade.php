{{-- How deploy.me reaches the server: direct, a jump host, a SOCKS5 or HTTP proxy, or a
     VPN on this machine. The Console theme has its own route.conf of this. --}}
@php
    use App\Enums\RouteKind;
    $kind = RouteKind::tryFrom($routeKind) ?? RouteKind::Direct;
    $savedKind = $server->route_kind ?? RouteKind::Direct;
@endphp
<form wire:submit="saveRoute" class="card bg-base-100 border border-line max-w-3xl" aria-label="Connection route">
    <header class="flex flex-wrap items-center gap-2.5 min-h-[52px] px-4 py-2 border-b border-line">
        <h2 class="text-[15px] font-semibold">Connection route</h2>
        <span class="text-[13px] text-muted">How deploy.me reaches {{ $server->name }}: every probe, deploy, folder sync and health check goes this way</span>
        @if ($routeNotice)<span class="text-[13px] text-success inline-flex items-center gap-1"><x-icon name="check" :size="12" /> {{ $routeNotice }}</span>@endif
    </header>
    <div class="p-4 flex flex-col gap-4">
        <div class="grid grid-cols-[repeat(auto-fit,minmax(128px,1fr))] gap-2" role="radiogroup" aria-label="Route">
            @foreach ($routeKinds as $option)
                <button type="button" role="radio" aria-checked="{{ $kind === $option ? 'true' : 'false' }}" wire:click="pickRouteKind(@js($option->value))"
                        @class(['flex flex-col gap-1 rounded-box border p-3 text-left', 'border-primary bg-primary/5' => $kind === $option, 'border-line hover:bg-base-200/60' => $kind !== $option])>
                    <span class="text-[13px] font-semibold">{{ $option->label() }}</span>
                    <span class="text-xs text-muted">{{ $option->note() }}</span>
                </button>
            @endforeach
        </div>

        @if ($kind === RouteKind::Jump)
            <label class="flex flex-col gap-1.5">
                <span class="text-[13px] font-medium">Jump host</span>
                <input type="text" wire:model="routeJump" list="route-jumps" class="input w-full font-mono" placeholder="bastion" spellcheck="false">
                <datalist id="route-jumps">@foreach ($jumpChoices as $alias)<option value="{{ $alias }}"></option>@endforeach</datalist>
                <span class="text-xs text-muted">An alias from your ~/.ssh/config, or user@host:port. Its own keys and host key work as they do for any server.</span>
                @error('routeJump')<span class="text-error text-xs">{{ $message }}</span>@enderror
            </label>
        @elseif ($kind->isProxy())
            <div class="grid grid-cols-[minmax(0,2fr)_minmax(0,1fr)] gap-3">
                <label class="flex flex-col gap-1.5">
                    <span class="text-[13px] font-medium">Proxy host</span>
                    <input type="text" wire:model="routeHost" class="input w-full font-mono" placeholder="10.0.0.2" spellcheck="false">
                    <span class="text-xs text-muted">As the deploy.me containers see it. A proxy on this machine is <span class="mono">host.docker.internal</span>.</span>
                    @error('routeHost')<span class="text-error text-xs">{{ $message }}</span>@enderror
                </label>
                <label class="flex flex-col gap-1.5">
                    <span class="text-[13px] font-medium">Port</span>
                    <input type="text" inputmode="numeric" wire:model="routePort" class="input w-full font-mono" placeholder="{{ $kind->defaultPort() }}">
                    @error('routePort')<span class="text-error text-xs">{{ $message }}</span>@enderror
                </label>
                <label class="flex flex-col gap-1.5">
                    <span class="text-[13px] font-medium">User <span class="text-muted font-normal">optional</span></span>
                    <input type="text" wire:model="routeUser" class="input w-full font-mono" autocomplete="off" spellcheck="false">
                    @error('routeUser')<span class="text-error text-xs">{{ $message }}</span>@enderror
                </label>
                <label class="flex flex-col gap-1.5">
                    <span class="text-[13px] font-medium">Password</span>
                    <input type="password" wire:model="routePassword" class="input w-full font-mono" autocomplete="new-password" placeholder="{{ $server->route_secret && $savedKind->isProxy() ? 'saved' : '' }}">
                    <span class="text-xs text-muted">{{ $server->route_secret && $savedKind->isProxy() ? 'Saved, encrypted. Type a new one to replace it.' : 'Stored encrypted; never shown again.' }}</span>
                    @error('routePassword')<span class="text-error text-xs">{{ $message }}</span>@enderror
                </label>
            </div>
        @elseif ($kind === RouteKind::Vpn)
            <div class="grid grid-cols-[minmax(0,2fr)_minmax(0,1fr)] gap-3">
                <label class="flex flex-col gap-1.5">
                    <span class="text-[13px] font-medium">Check address <span class="text-muted font-normal">optional</span></span>
                    <input type="text" wire:model="routeCheckHost" class="input w-full font-mono" placeholder="10.8.0.1" spellcheck="false">
                    <span class="text-xs text-muted">Something only the VPN reaches, like its gateway: when it doesn’t answer, the VPN is down. deploy.me doesn’t start the VPN.</span>
                    @error('routeCheckHost')<span class="text-error text-xs">{{ $message }}</span>@enderror
                </label>
                <label class="flex flex-col gap-1.5">
                    <span class="text-[13px] font-medium">Port</span>
                    <input type="text" inputmode="numeric" wire:model="routeCheckPort" class="input w-full font-mono" placeholder="53">
                    @error('routeCheckPort')<span class="text-error text-xs">{{ $message }}</span>@enderror
                </label>
            </div>
        @endif

        @if ($server->hasRoute() && $server->route_checked_at)
            <div class="rounded-box bg-sunk p-3 font-mono text-[12px] leading-5 flex flex-col gap-1">
                <span class="font-medium">Last check · {{ $server->route_checked_at->diffForHumans() }}</span>
                <span><span @class(['text-success' => $server->route_state?->ok(), 'text-error' => ! $server->route_state?->ok()])>{{ $server->route_state?->ok() ? '✓' : '✕' }}</span> {{ $server->route_detail }}</span>
            </div>
        @endif

        <p class="text-xs text-muted">
            @if ($kind === RouteKind::Direct)
                Nothing in between: ssh connects as your ~/.ssh/config says.
            @else
                The route is checked before every probe and deploy. When it’s down, the probe marks the server offline and a deploy stops at pre-flight with the reason; nothing on the server changes.
            @endif
        </p>
    </div>
    <div class="flex items-center gap-2 px-4 py-3 border-t border-line">
        @if ($server->hasRoute())
            <button type="button" class="btn btn-sm" wire:click="checkRoute" wire:loading.attr="disabled" wire:target="checkRoute,saveRoute">Check route</button>
        @endif
        <span class="grow"></span>
        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveRoute"><x-icon name="check" /> Save route</button>
    </div>
</form>
