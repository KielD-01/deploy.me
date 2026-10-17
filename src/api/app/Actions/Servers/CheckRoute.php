<?php

namespace App\Actions\Servers;

use App\Enums\RouteKind;
use App\Enums\RouteState;
use App\Models\Deployment;
use App\Models\RouteCheck;
use App\Models\Server;
use App\Platform\Ssh\Exceptions\SshException;
use App\Platform\Ssh\Route\ProxyTunnel;
use App\Platform\Ssh\Route\RouteProbe;
use App\Platform\Ssh\SshTargetResolver;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Checks the way to a server before anything uses it: the VPN's check address
 * or the proxy or jump host answers, and the server's ssh port answers
 * through it with an ssh banner. Nothing is signed in to and nothing runs on
 * the server. Each result is kept (route_checks) and the last one also lives
 * on the server. A server reached directly has no route to check.
 */
class CheckRoute
{
    public function __construct(
        private readonly RouteProbe $probe,
        private readonly SshTargetResolver $resolver,
    ) {}

    /**
     * @param  string  $reason  manual, probe, deploy or doctor (RouteCheck::REASONS)
     */
    public function __invoke(Server $server, string $reason, ?Deployment $deployment = null): ?RouteCheck
    {
        if (! $server->hasRoute()) {
            return null;
        }

        $route = $server->connectionRoute();
        $timeout = (float) config('deployme.ssh.route_timeout', 5);
        $started = microtime(true);

        [$state, $detail] = $this->check($server, $timeout);

        $check = $server->routeChecks()->create([
            'kind' => $route->kind,
            'state' => $state,
            'reason' => $reason,
            'deployment_id' => $deployment?->id,
            'detail' => Str::limit($detail, 297),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'checked_at' => now(),
        ]);

        $server->forceFill([
            'route_state' => $state,
            'route_detail' => $check->detail,
            'route_checked_at' => $check->checked_at,
        ])->save();

        return $check;
    }

    /**
     * @return array{0: RouteState, 1: string}
     */
    private function check(Server $server, float $timeout): array
    {
        $route = $server->connectionRoute();

        if (($problem = $route->problem($server->ssh_alias)) !== null) {
            return [RouteState::RouteDown, 'The route’s settings aren’t complete: '.$problem[1]];
        }

        try {
            $ssh = $this->resolver->resolve($server->ssh_alias);
        } catch (SshException $e) {
            return [RouteState::ServerUnreachable, 'ssh can’t resolve '.$server->ssh_alias.': '.$e->userMessage()];
        }

        $address = "{$ssh->hostName}:{$ssh->port}";
        $way = '';

        try {
            if ($route->kind === RouteKind::Vpn && $route->checkHost !== null) {
                $this->probe->connect($route->checkHost, (int) $route->checkPort, $timeout);
                $way = "VPN {$route->checkHost}:{$route->checkPort} answered · ";
            }

            $banner = match ($route->kind) {
                RouteKind::Jump => $this->probe->jump((string) $route->jump, $ssh->hostName, $ssh->port, $timeout),
                default => $this->probe->banner($route, $ssh->hostName, $ssh->port, $timeout),
            };
        } catch (RuntimeException $e) {
            $down = $e->getCode() !== ProxyTunnel::SERVER_DOWN;

            if ($route->kind === RouteKind::Vpn) {
                return $way === ''
                    ? [RouteState::RouteDown, "Nothing answers at {$route->checkHost}:{$route->checkPort}, the VPN’s check address: ".lcfirst(rtrim($e->getMessage(), '.')).'. Is the VPN connected on this machine?']
                    : [RouteState::ServerUnreachable, "The VPN answers, but {$address} doesn’t: ".lcfirst($e->getMessage())];
            }

            return [$down ? RouteState::RouteDown : RouteState::ServerUnreachable, $e->getMessage()];
        }

        if (! str_starts_with($banner, 'SSH-')) {
            return [RouteState::ServerUnreachable, "{$address} answered, but not as an ssh server: ".Str::limit($banner, 60)];
        }

        $through = match ($route->kind) {
            RouteKind::Jump => "jump host {$route->jump} → ",
            RouteKind::Socks5, RouteKind::Http => $route->label().' → ',
            default => $way,
        };

        return [RouteState::Up, "{$through}{$address} answered ".Str::limit($banner, 60)];
    }
}
