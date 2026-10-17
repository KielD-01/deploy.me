<?php

/*
 * deploy.me's ssh ProxyCommand for a server reached through a SOCKS5 or HTTP
 * proxy (see SshConfigCompiler):
 *
 *     ProxyCommand php deployme-ssh-proxy.php /run/deployme/ssh/routes/<id>.json %h %p
 *
 * The proxy's address and credentials come from that 0600 file on the
 * memory-only runtime folder, never from argv. Whatever goes wrong is one
 * line on stderr, and the same line in <id>.err beside the file: with
 * ControlPersist on, ssh sends a ProxyCommand's stderr to /dev/null, so
 * that's where deploy.me reads why (SshConfigCompiler::routeError).
 */

require __DIR__.'/../../app/Platform/Ssh/Route/ProxyTunnel.php';

use App\Platform\Ssh\Route\ProxyTunnel;

[, $file, $host, $port] = $argv + [null, null, null, null];
$route = is_string($file) && is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
$errors = is_string($file) ? preg_replace('/\.json$/', '.err', $file) : null;

$fail = function (string $why) use ($errors): never {
    fwrite(STDERR, "deploy.me: {$why}\n");
    if ($errors !== null && $errors !== '') {
        @file_put_contents($errors, $why);
        @chmod($errors, 0600);
    }
    exit(255);
};

if (! is_array($route) || ! is_string($host) || ! ctype_digit((string) $port)) {
    $fail('The proxy settings for this server are missing; save the route again.');
}

try {
    $socket = ProxyTunnel::open(
        (string) $route['kind'],
        (string) $route['host'],
        (int) $route['port'],
        $route['user'] ?? null,
        $route['password'] ?? null,
        $host,
        (int) $port,
        (float) ($route['timeout'] ?? 10),
    );
} catch (RuntimeException $e) {
    $fail($e->getMessage());
}

@unlink($errors);

ProxyTunnel::pump(STDIN, STDOUT, $socket);
