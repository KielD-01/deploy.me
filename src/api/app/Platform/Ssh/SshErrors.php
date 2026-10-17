<?php

namespace App\Platform\Ssh;

use App\Platform\Ssh\Exceptions\AuthenticationFailed;
use App\Platform\Ssh\Exceptions\HostKeyChanged;
use App\Platform\Ssh\Exceptions\HostKeyUnknown;
use App\Platform\Ssh\Exceptions\HostUnreachable;
use App\Platform\Ssh\Exceptions\RemoteCommandFailed;
use App\Platform\Ssh\Exceptions\SshConfigError;
use App\Platform\Ssh\Exceptions\SshException;

/**
 * Turns a failed ssh run into a typed exception. ssh itself exits 255; any
 * other code is the remote command's own.
 */
final class SshErrors
{
    public static function from(SshResult $result): SshException
    {
        $stderr = $result->stderr;
        $message = trim(preg_replace('/\s+/', ' ', $stderr)) ?: "Exited with code {$result->exitCode}.";

        // deploy.me's own ProxyCommand says in one line why the proxy let it down.
        if (preg_match('/^deploy\.me: (.+)$/m', $stderr, $proxy) === 1) {
            return new HostUnreachable(trim($proxy[1]), $stderr, $result->exitCode);
        }

        if ($result->exitCode === 255 || $result->stdout === '') {
            $exception = match (true) {
                (bool) preg_match('/IDENTIFICATION HAS CHANGED/i', $stderr) => HostKeyChanged::class,
                (bool) preg_match('/Host key verification failed|No \S+ host key is known|host key is not known/i', $stderr) => HostKeyUnknown::class,
                (bool) preg_match('/Permission denied \(|Too many authentication failures/i', $stderr) => AuthenticationFailed::class,
                (bool) preg_match('/Could not resolve hostname|Connection refused|timed out|No route to host|Network is unreachable|kex_exchange_identification|Connection closed by|Connection reset/i', $stderr) => HostUnreachable::class,
                (bool) preg_match('/Bad configuration option|Can\'t open user config|Unsupported option|keyword \S+ extra arguments|garbage at end of line/i', $stderr) => SshConfigError::class,
                default => null,
            };

            if ($exception !== null) {
                return new $exception($message, $stderr, $result->exitCode);
            }
        }

        return new RemoteCommandFailed($message, $stderr, $result->exitCode);
    }
}
