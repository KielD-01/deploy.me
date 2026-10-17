<?php

namespace App\Deploy\Pipeline;

/**
 * Where a retry of a finished deploy starts.
 */
final readonly class ResumePoint
{
    public function __construct(
        // The step, as the run recorded it.
        public string $key,
        public string $label,
        // The release isn't live any more: the retry switches to it again
        // before that step, and runs what comes after the switch again.
        public bool $switchesBack,
    ) {}
}
