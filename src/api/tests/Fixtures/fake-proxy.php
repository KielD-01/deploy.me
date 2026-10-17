<?php

/*
 * A small SOCKS5 / HTTP CONNECT proxy for the route tests:
 *
 *     php fake-proxy.php <socks5|http> <user|-> <password|-> <port file>
 *
 * It listens on 127.0.0.1 (a free port, written to the port file), forks per
 * connection and forwards to whatever is asked for. One name is its own:
 * banner.test answers with an ssh banner and then echoes what it gets, so a
 * test needs no real server behind it. A connection it can't make is
 * refused the way each protocol says so.
 */

[, $kind, $user, $password, $portFile] = $argv;
$user = $user === '-' ? null : $user;
$password = $password === '-' ? null : $password;

// Its own process group, so the test stops it and its forks in one go.
posix_setpgid(0, 0);

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
file_put_contents($portFile, (string) parse_url('tcp://'.stream_socket_get_name($server, false), PHP_URL_PORT));
pcntl_signal(SIGCHLD, SIG_IGN);

while (true) {
    $client = @stream_socket_accept($server, -1);

    if ($client === false) {
        continue;
    }

    if (pcntl_fork() === 0) {
        fclose($server);
        handle($client, $kind, $user, $password);
        exit(0);
    }

    fclose($client);
}

function handle($client, string $kind, ?string $user, ?string $password): void
{
    $target = $kind === 'http' ? httpHandshake($client, $user, $password) : socksHandshake($client, $user, $password);

    if ($target === null) {
        return;
    }

    [$host, $port, $ok, $fail] = $target;

    if ($host === 'banner.test') {
        fwrite($client, $ok);
        fwrite($client, "SSH-2.0-FakeProxyServer\r\n");
        while (($line = fgets($client)) !== false) {
            fwrite($client, $line);
        }

        return;
    }

    $upstream = @stream_socket_client("tcp://{$host}:{$port}", $errno, $error, 3);

    if ($upstream === false) {
        fwrite($client, $fail);

        return;
    }

    fwrite($client, $ok);

    while (true) {
        $read = [$client, $upstream];
        $write = $except = null;
        if (stream_select($read, $write, $except, null) === false) {
            return;
        }
        foreach ($read as $stream) {
            $data = fread($stream, 65536);
            if ($data === '' || $data === false) {
                return;
            }
            fwrite($stream === $client ? $upstream : $client, $data);
        }
    }
}

function readBytes($stream, int $length): string
{
    $data = '';
    while (strlen($data) < $length && ! feof($stream)) {
        $data .= fread($stream, $length - strlen($data));
    }

    return $data;
}

function socksHandshake($client, ?string $user, ?string $password): ?array
{
    $head = unpack('C2', readBytes($client, 2));
    $methods = array_values(unpack('C*', readBytes($client, $head[2])));
    $want = $user === null ? 0 : 2;

    if (! in_array($want, $methods, true)) {
        fwrite($client, "\x05\xFF");

        return null;
    }

    fwrite($client, "\x05".chr($want));

    if ($want === 2) {
        readBytes($client, 1);
        $givenUser = readBytes($client, ord(readBytes($client, 1)));
        $givenPassword = readBytes($client, ord(readBytes($client, 1)));

        if ($givenUser !== $user || $givenPassword !== $password) {
            fwrite($client, "\x01\x01");

            return null;
        }

        fwrite($client, "\x01\x00");
    }

    $request = unpack('C4', readBytes($client, 4));
    $host = match ($request[4]) {
        1 => inet_ntop(readBytes($client, 4)),
        4 => inet_ntop(readBytes($client, 16)),
        3 => readBytes($client, ord(readBytes($client, 1))),
    };
    $port = unpack('n', readBytes($client, 2))[1];

    return [$host, $port, "\x05\x00\x00\x01\x7F\x00\x00\x01\x00\x00", "\x05\x05\x00\x01\x00\x00\x00\x00\x00\x00"];
}

function httpHandshake($client, ?string $user, ?string $password): ?array
{
    $head = '';
    while (! str_ends_with($head, "\r\n\r\n") && ! feof($client)) {
        $head .= fread($client, 1);
    }

    preg_match('/^CONNECT (\S+):(\d+) HTTP/', $head, $match);
    $auth = 'Proxy-Authorization: Basic '.base64_encode("{$user}:{$password}");

    if ($user !== null && ! str_contains($head, $auth)) {
        fwrite($client, "HTTP/1.1 407 Proxy Authentication Required\r\n\r\n");

        return null;
    }

    return [trim($match[1], '[]'), (int) $match[2], "HTTP/1.1 200 Connection established\r\n\r\n", "HTTP/1.1 502 Bad Gateway\r\n\r\n"];
}
