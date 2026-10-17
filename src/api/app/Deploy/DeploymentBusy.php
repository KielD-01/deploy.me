<?php

namespace App\Deploy;

use RuntimeException;

/**
 * A deploy is already queued or running on the target.
 */
final class DeploymentBusy extends RuntimeException {}
