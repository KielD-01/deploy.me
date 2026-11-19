<?php

namespace App\Platform\Ssh\Exceptions;

use App\Platform\Ssh\KeyFile;

class AuthenticationFailed extends SshException
{
    public function userMessage(): string
    {
        // ssh skips a key others can read, then tries the rest and is refused.
        if (preg_match("/Permissions (\\d+) for '([^']+)' are too open/", $this->stderr, $m) === 1) {
            $sshDir = rtrim(KeyFile::sshDir(), '/').'/';
            $file = str_starts_with($m[2], $sshDir) ? '~/.ssh/'.substr($m[2], strlen($sshDir)) : $m[2];

            return "ssh ignored the key {$file}: others can read it ({$m[1]}). Run on this machine: chmod 600 {$file}";
        }

        return 'The server refused the key. Check that the IdentityFile for this host is in the server’s authorized_keys.';
    }
}
