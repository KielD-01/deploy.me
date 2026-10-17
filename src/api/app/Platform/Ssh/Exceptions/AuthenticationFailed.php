<?php

namespace App\Platform\Ssh\Exceptions;

class AuthenticationFailed extends SshException
{
    public function userMessage(): string
    {
        return 'The server refused the key. Check that the IdentityFile for this host is in the server’s authorized_keys.';
    }
}
