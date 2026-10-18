<?php

namespace App\Deploy\Pipeline;

use RuntimeException;

/**
 * A step failed, but it may: an action step marked continue-on-error. The
 * runner records it as failed and goes on with the next step.
 */
final class StepCarriedOn extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $exitCode = null)
    {
        parent::__construct($message);
    }
}
