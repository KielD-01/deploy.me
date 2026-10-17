<?php

namespace App\Deploy\Pipeline;

use App\Deploy\DeployContext;

/**
 * One step of a deploy pipeline, shown as a row on the run page.
 */
interface Step
{
    public function key(): string;

    public function label(): string;

    /**
     * False marks the step skipped (nothing to do for this project).
     */
    public function shouldRun(DeployContext $context): bool;

    /**
     * Does the work and returns the detail shown next to the step, if any.
     *
     * @throws StepFailed
     */
    public function run(DeployContext $context): ?string;
}
