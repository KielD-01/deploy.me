<?php

namespace App\Platform\Ssh\Exceptions;

class HostKeyUnknown extends SshException
{
    public function userMessage(): string
    {
        return 'This server’s host key isn’t trusted yet. Compare its fingerprint, then trust it.';
    }
}
