<?php

namespace App\Deploy\Delivery;

use App\Enums\DeliveryStrategyKey;
use App\Models\Project;

/**
 * One way to get a project's code onto a server.
 */
interface DeliveryStrategy
{
    public function key(): DeliveryStrategyKey;

    /**
     * Whether it can deliver the project now (a particular commit, or the
     * branch's latest), and what it would deliver.
     */
    public function evaluate(Project $project, ?string $commit): Verdict;
}
