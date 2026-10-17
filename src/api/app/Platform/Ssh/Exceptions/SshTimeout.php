<?php

namespace App\Platform\Ssh\Exceptions;

class SshTimeout extends SshException
{
    public function userMessage(): string
    {
        return 'The server didn’t answer in time.';
    }
}
