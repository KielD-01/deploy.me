<?php

namespace App\Deploy\Pipeline;

use RuntimeException;

final class DeploymentCancelled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Cancelled.');
    }
}
