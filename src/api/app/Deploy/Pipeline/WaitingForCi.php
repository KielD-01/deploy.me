<?php

namespace App\Deploy\Pipeline;

use App\Deploy\Delivery\Resolution;
use RuntimeException;

/**
 * The strategy ahead of the rest waits for CI: the run pauses, holding
 * nothing on the server, and checks again later.
 */
final class WaitingForCi extends RuntimeException
{
    public function __construct(public readonly Resolution $resolution)
    {
        parent::__construct($resolution->pending?->reason ?? 'Waiting for CI.');
    }
}
