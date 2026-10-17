<?php

namespace App\Platform\Ssh\Exceptions;

class HostUnreachable extends SshException
{
    public function userMessage(): string
    {
        return 'Couldn’t reach the server: '.$this->getMessage();
    }
}
