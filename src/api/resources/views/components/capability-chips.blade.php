@props(['capabilities' => []])
@php
    $c = $capabilities;
    // [ok | warn | info, label]: info marks something optional that's simply absent.
    $chips = [
        [$c['docker'] ?? null ? 'ok' : 'warn', ($c['docker'] ?? null) ? "Docker {$c['docker']}" : 'No Docker'],
        [$c['compose'] ?? null ? 'ok' : 'warn', ($c['compose'] ?? null) ? "Compose v{$c['compose']}" : 'No Compose'],
        [$c['git'] ?? null ? 'ok' : 'warn', ($c['git'] ?? null) ? "git {$c['git']}" : 'No git'],
        [$c['rsync'] ?? null ? 'ok' : 'warn', ($c['rsync'] ?? null) ? "rsync {$c['rsync']}" : 'No rsync'],
        [($c['gnu_mv'] ?? false) && ($c['flock'] ?? false) ? 'ok' : 'warn', 'GNU mv -T and flock'],
        [($c['caddy_docker_proxy'] ?? null) && ($c['caddy_on_network'] ?? false) ? 'ok' : 'info', ($c['caddy_docker_proxy'] ?? null) ? 'caddy-docker-proxy on proxy-network' : 'No edge proxy found'],
        [($c['docker_group'] ?? false) ? 'ok' : 'warn', ($c['docker_group'] ?? false) ? 'In docker group' : 'Not in docker group'],
        [($c['sudo_nopasswd'] ?? false) ? 'ok' : 'warn', ($c['sudo_nopasswd'] ?? false) ? 'Passwordless sudo' : 'sudo needs a password'],
    ];
@endphp
<div {{ $attributes->merge(['class' => 'flex flex-wrap gap-2']) }}>
    @foreach ($chips as [$state, $label])
        <span class="inline-flex items-center gap-1.5 h-6 px-2 rounded-md border border-line bg-base-100 text-xs">
            <x-icon :name="['ok' => 'check', 'warn' => 'alert', 'info' => 'info'][$state]" :size="12" @class(['text-success' => $state === 'ok', 'text-warning' => $state === 'warn', 'text-muted' => $state === 'info']) />
            {{ $label }}
        </span>
    @endforeach
</div>
