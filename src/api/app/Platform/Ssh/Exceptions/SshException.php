<?php

namespace App\Platform\Ssh\Exceptions;

use RuntimeException;

class SshException extends RuntimeException
{
    public function __construct(string $message, public readonly string $stderr = '', public readonly int $exitCode = 255)
    {
        parent::__construct($message);
    }

    /**
     * What to show a person: what happened and what to do about it.
     */
    public function userMessage(): string
    {
        return $this->getMessage();
    }
}
