<?php

namespace App\Platform\Ssh\Route;

use RuntimeException;

/**
 * Opens a TCP connection to host:port through a SOCKS5 (RFC 1928, with the
 * RFC 1929 user and password) or HTTP CONNECT proxy, and pumps bytes through
 * it. Plain PHP with no framework: the ssh ProxyCommand helper
 * (resources/bin/deployme-ssh-proxy.php) loads this file on its own, and the
 * route check uses the same code, so a check proves the path ssh takes.
 */
final class ProxyTunnel
{
    // The proxy itself can't be reached, or won't let us in.
    public const PROXY_DOWN = 1;

    // The proxy is fine, but it can't reach the server.
    public const SERVER_DOWN = 2;

    /**
     * @return resource a blocking stream, connected to host:port
     *
     * @throws RuntimeException with PROXY_DOWN or SERVER_DOWN as its code
     */
    public static function open(string $kind, string $proxyHost, int $proxyPort, ?string $user, #[\SensitiveParameter] ?string $password, string $host, int $port, float $timeout)
    {
        $name = $kind === 'http' ? 'HTTP proxy' : 'SOCKS5 proxy';
        $socket = self::connect($proxyHost, $proxyPort, $timeout, "The {$name} at {$proxyHost}:{$proxyPort}", self::PROXY_DOWN);
        $deadline = microtime(true) + $timeout;

        try {
            $kind === 'http'
                ? self::httpConnect($socket, $name, $user, $password, $host, $port, $deadline)
                : self::socks5Connect($socket, $name, $user, $password, $host, $port, $deadline);
        } catch (RuntimeException $e) {
            fclose($socket);

            throw $e;
        }

        stream_set_timeout($socket, 86400);

        return $socket;
    }

    /**
     * @return resource
     */
    public static function connect(string $host, int $port, float $timeout, string $what, int $code)
    {
        $address = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? "[{$host}]" : $host;
        // The failure is reported below, in words: not as a PHP warning too.
        set_error_handler(fn () => true);

        try {
            $socket = stream_socket_client("tcp://{$address}:{$port}", $errno, $error, $timeout);
        } finally {
            restore_error_handler();
        }

        if ($socket === false) {
            $why = match (true) {
                $errno === 0 && $error === '' => 'couldn’t be resolved',
                str_contains(strtolower($error), 'refused') => 'refused the connection',
                str_contains(strtolower($error), 'timed out') => 'didn’t answer within '.round($timeout).' s',
                default => 'couldn’t be reached ('.trim($error).')',
            };

            throw new RuntimeException("{$what} {$why}.", $code);
        }

        stream_set_read_buffer($socket, 0);

        return $socket;
    }

    /**
     * @param  resource  $socket
     */
    private static function socks5Connect($socket, string $name, ?string $user, #[\SensitiveParameter] ?string $password, string $host, int $port, float $deadline): void
    {
        $withLogin = $user !== null && $user !== '';
        self::write($socket, "\x05".($withLogin ? "\x02\x00\x02" : "\x01\x00"));
        [$version, $method] = array_values(unpack('C2', self::read($socket, 2, $deadline, $name)));

        if ($version !== 5) {
            throw new RuntimeException("The {$name} doesn’t speak SOCKS5.", self::PROXY_DOWN);
        }

        if ($method === 0xFF || ($method === 2 && ! $withLogin)) {
            throw new RuntimeException("The {$name} wants a user and password.", self::PROXY_DOWN);
        }

        if ($method === 2) {
            self::write($socket, "\x01".chr(strlen($user)).$user.chr(strlen((string) $password)).$password);
            $status = unpack('C2', self::read($socket, 2, $deadline, $name))[2];

            if ($status !== 0) {
                throw new RuntimeException("The {$name} turned down the user and password.", self::PROXY_DOWN);
            }
        } elseif ($method !== 0) {
            throw new RuntimeException("The {$name} offered no sign-in deploy.me speaks.", self::PROXY_DOWN);
        }

        $bare = trim($host, '[]');
        $address = match (true) {
            filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => "\x01".inet_pton($bare),
            filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => "\x04".inet_pton($bare),
            default => "\x03".chr(strlen($host)).$host,
        };
        self::write($socket, "\x05\x01\x00".$address.pack('n', $port));

        [, $reply, , $type] = array_values(unpack('C4', self::read($socket, 4, $deadline, $name)));

        if ($reply !== 0) {
            $why = match ($reply) {
                3 => 'network unreachable',
                4 => 'host unreachable',
                5 => 'connection refused',
                6 => 'timed out',
                2 => 'not allowed by its rules',
                default => "error {$reply}",
            };

            throw new RuntimeException("The {$name} couldn’t reach {$host}:{$port}: {$why}.", in_array($reply, [3, 4, 5, 6], true) ? self::SERVER_DOWN : self::PROXY_DOWN);
        }

        // The address the proxy bound: read and dropped.
        $length = match ($type) {
            1 => 4,
            4 => 16,
            3 => unpack('C', self::read($socket, 1, $deadline, $name))[1],
            default => throw new RuntimeException("The {$name} sent an answer deploy.me can’t read.", self::PROXY_DOWN),
        };
        self::read($socket, $length + 2, $deadline, $name);
    }

    /**
     * @param  resource  $socket
     */
    private static function httpConnect($socket, string $name, ?string $user, #[\SensitiveParameter] ?string $password, string $host, int $port, float $deadline): void
    {
        $authority = (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '['.trim($host, '[]').']' : $host).':'.$port;
        $request = "CONNECT {$authority} HTTP/1.1\r\nHost: {$authority}\r\n";

        if ($user !== null && $user !== '') {
            $request .= 'Proxy-Authorization: Basic '.base64_encode($user.':'.$password)."\r\n";
        }

        self::write($socket, $request."\r\n");

        // Byte by byte up to the blank line, so nothing ssh should get is read here.
        $head = '';
        while (! str_ends_with($head, "\r\n\r\n")) {
            if (strlen($head) > 8192) {
                throw new RuntimeException("The {$name} sent an answer deploy.me can’t read.", self::PROXY_DOWN);
            }
            $head .= self::read($socket, 1, $deadline, $name);
        }

        $status = (int) (explode(' ', strtok($head, "\r\n"), 3)[1] ?? 0);

        if ($status >= 200 && $status < 300) {
            return;
        }

        throw match (true) {
            $status === 407 => new RuntimeException($user ? "The {$name} turned down the user and password." : "The {$name} wants a user and password.", self::PROXY_DOWN),
            in_array($status, [502, 503, 504], true) => new RuntimeException("The {$name} couldn’t reach {$host}:{$port} (HTTP {$status}).", self::SERVER_DOWN),
            default => new RuntimeException("The {$name} refused to connect to {$host}:{$port} (HTTP {$status}).", self::PROXY_DOWN),
        };
    }

    /**
     * Copies stdin to the connection and the connection to stdout until
     * either side is done, the way `nc` does for ssh.
     *
     * @param  resource  $in
     * @param  resource  $out
     * @param  resource  $socket
     */
    public static function pump($in, $out, $socket): void
    {
        stream_set_read_buffer($in, 0);
        $inOpen = true;

        while (true) {
            $read = $inOpen ? [$in, $socket] : [$socket];
            $write = $except = null;

            if (@stream_select($read, $write, $except, null) === false) {
                return;
            }

            foreach ($read as $stream) {
                $data = fread($stream, 65536);

                if ($data === false || $data === '') {
                    if ($stream === $socket) {
                        return;
                    }

                    // ssh closed its side: say so to the server and keep reading.
                    $inOpen = false;
                    stream_socket_shutdown($socket, STREAM_SHUT_WR);

                    continue;
                }

                self::write($stream === $socket ? $out : $socket, $data);
            }
        }
    }

    /**
     * Reads the first line a server sends: an ssh server says who it is.
     *
     * @param  resource  $socket
     */
    public static function banner($socket, float $timeout): ?string
    {
        stream_set_timeout($socket, (int) ceil($timeout));
        $line = fgets($socket, 256);

        return $line === false ? null : trim($line);
    }

    /**
     * @param  resource  $stream
     */
    private static function write($stream, string $data): void
    {
        while ($data !== '') {
            $written = @fwrite($stream, $data);

            if ($written === false || $written === 0) {
                throw new RuntimeException('The connection closed while writing.', self::PROXY_DOWN);
            }

            $data = substr($data, $written);
        }
    }

    /**
     * @param  resource  $socket
     */
    private static function read($socket, int $length, float $deadline, string $name): string
    {
        $data = '';

        while (strlen($data) < $length) {
            $left = $deadline - microtime(true);

            if ($left <= 0) {
                throw new RuntimeException("The {$name} stopped answering.", self::PROXY_DOWN);
            }

            stream_set_timeout($socket, (int) $left, (int) (fmod($left, 1) * 1e6));
            $chunk = fread($socket, $length - strlen($data));

            if ($chunk === false || $chunk === '') {
                if (stream_get_meta_data($socket)['timed_out']) {
                    throw new RuntimeException("The {$name} stopped answering.", self::PROXY_DOWN);
                }

                if (feof($socket)) {
                    throw new RuntimeException("The {$name} closed the connection.", self::PROXY_DOWN);
                }

                continue;
            }

            $data .= $chunk;
        }

        return $data;
    }
}
