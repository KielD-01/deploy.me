{{-- The Console theme's route.conf: how deploy.me reaches the server, and the last check. --}}
@php
    use App\Enums\RouteKind;
    use Illuminate\Support\Str;
    $kind = RouteKind::tryFrom($routeKind) ?? RouteKind::Direct;
    $savedKind = $server->route_kind ?? RouteKind::Direct;
    $keys = [RouteKind::Direct->value => 'direct', RouteKind::Jump->value => 'jump', RouteKind::Socks5->value => 'socks5', RouteKind::Http->value => 'http', RouteKind::Vpn->value => 'vpn'];
@endphp
<form wire:submit="saveRoute" class="con-box con-conf" aria-label="Connection route">
    <span class="con-box-t"><b>route.conf</b> · how deploy.me reaches {{ $server->slug }}@if ($routeNotice)<span class="t-ok">· ✓ {{ $routeNotice }}</span>@endif</span>
    <div class="grid grid-cols-[12ch_minmax(0,1fr)] items-center gap-x-3 gap-y-2">
        <span class="t-v">via</span>
        <div class="flex flex-wrap gap-1.5" role="radiogroup" aria-label="Route">
            @foreach ($routeKinds as $option)
                <button type="button" role="radio" aria-checked="{{ $kind === $option ? 'true' : 'false' }}" title="{{ $option->note() }}" wire:click="pickRouteKind(@js($option->value))" @class(['con-act', 'border-primary text-primary' => $kind === $option])>{{ $keys[$option->value] }}</button>
            @endforeach
        </div>

        @if ($kind === RouteKind::Jump)
            <label for="route-jump" class="t-v">jump</label>
            <div>
                <input id="route-jump" type="text" wire:model="routeJump" list="route-jumps-con" class="input input-sm w-full max-w-md" placeholder="bastion" spellcheck="false">
                <datalist id="route-jumps-con">@foreach ($jumpChoices as $alias)<option value="{{ $alias }}"></option>@endforeach</datalist>
                @error('routeJump')<span class="t-err block">{{ $message }}</span>@enderror
            </div>
        @elseif ($kind->isProxy())
            <label for="route-host" class="t-v">proxy_host</label>
            <div>
                <input id="route-host" type="text" wire:model="routeHost" class="input input-sm w-full max-w-md" placeholder="10.0.0.2" spellcheck="false">
                @error('routeHost')<span class="t-err block">{{ $message }}</span>@enderror
            </div>
            <label for="route-port" class="t-v">proxy_port</label>
            <div>
                <input id="route-port" type="text" inputmode="numeric" wire:model="routePort" class="input input-sm w-[10ch]" placeholder="{{ $kind->defaultPort() }}">
                @error('routePort')<span class="t-err block">{{ $message }}</span>@enderror
            </div>
            <label for="route-user" class="t-v">proxy_user</label>
            <div>
                <input id="route-user" type="text" wire:model="routeUser" class="input input-sm w-full max-w-md" autocomplete="off" spellcheck="false">
                @error('routeUser')<span class="t-err block">{{ $message }}</span>@enderror
            </div>
            <label for="route-pass" class="t-v">proxy_pass</label>
            <div>
                <span class="flex flex-wrap items-center gap-2">
                    <input id="route-pass" type="password" wire:model="routePassword" class="input input-sm w-[24ch]" autocomplete="new-password" placeholder="{{ $server->route_secret && $savedKind->isProxy() ? 'saved' : '' }}">
                    <span class="t-faint"># {{ $server->route_secret && $savedKind->isProxy() ? 'encrypted · type to replace' : 'stored encrypted' }}</span>
                </span>
                @error('routePassword')<span class="t-err block">{{ $message }}</span>@enderror
            </div>
        @elseif ($kind === RouteKind::Vpn)
            <label for="route-check-host" class="t-v">check_host</label>
            <div>
                <input id="route-check-host" type="text" wire:model="routeCheckHost" class="input input-sm w-full max-w-md" placeholder="10.8.0.1" spellcheck="false">
                @error('routeCheckHost')<span class="t-err block">{{ $message }}</span>@enderror
            </div>
            <label for="route-check-port" class="t-v">check_port</label>
            <div>
                <input id="route-check-port" type="text" inputmode="numeric" wire:model="routeCheckPort" class="input input-sm w-[10ch]" placeholder="53">
                @error('routeCheckPort')<span class="t-err block">{{ $message }}</span>@enderror
            </div>
        @endif
    </div>

    <p class="mt-3 t-faint"># {{ $kind === RouteKind::Direct ? 'as your ~/.ssh/config says' : ($kind === RouteKind::Vpn ? 'the VPN runs on this machine; deploy.me checks it, never starts it · ' : '').'checked before every probe and deploy · a deploy stops at pre-flight when it’s down' }}</p>

    @if ($server->hasRoute() && $server->route_checked_at)
        <pre class="mt-3 whitespace-pre-wrap leading-[1.75]"><span class="t-mute">$</span> deployme route check {{ $server->slug }} <span class="t-faint">· {{ $server->route_checked_at->diffForHumans(short: true) }}</span>
<span class="{{ $server->route_state?->tone() }}">{{ $server->route_state?->ok() ? '✓' : '✕' }}</span> {{ $server->route_detail }}
<span class="{{ $server->route_state?->tone() }}">{{ $server->route_state?->ok() ? 'route up' : $server->route_state?->label() }}</span></pre>
    @endif

    <div class="flex items-center gap-2 mt-4 pt-3 border-t border-line">
        @if ($server->hasRoute())
            <button type="button" class="con-act" wire:click="checkRoute" wire:loading.attr="disabled" wire:target="checkRoute,saveRoute">check route</button>
        @endif
        <span class="grow"></span>
        <button type="submit" class="btn btn-sm btn-primary" wire:loading.attr="disabled" wire:target="saveRoute">Save</button>
    </div>
</form>
