<?php

namespace App\Deploy\Pipeline;

use RuntimeException;

/**
 * A step couldn't do its job. The message is what the run page shows.
 */
final class StepFailed extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $exitCode = null)
    {
        parent::__construct($message);
    }
}
