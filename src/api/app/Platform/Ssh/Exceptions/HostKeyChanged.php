<?php

namespace App\Platform\Ssh\Exceptions;

class HostKeyChanged extends SshException
{
    public function userMessage(): string
    {
        return 'The server’s host key changed since you trusted it. That happens after a reinstall, but it can also mean someone is intercepting the connection. Check with the server before trusting the new key.';
    }
}
