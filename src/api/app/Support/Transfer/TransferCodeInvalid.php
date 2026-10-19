<?php

namespace App\Support\Transfer;

use RuntimeException;

/**
 * A transfer code that can't be opened; the message says why, for the page.
 */
final class TransferCodeInvalid extends RuntimeException {}
