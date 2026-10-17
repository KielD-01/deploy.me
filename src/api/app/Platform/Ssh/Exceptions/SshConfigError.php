<?php

namespace App\Platform\Ssh\Exceptions;

class SshConfigError extends SshException
{
    public function userMessage(): string
    {
        return 'ssh couldn’t read the config for this host: '.$this->getMessage();
    }
}
