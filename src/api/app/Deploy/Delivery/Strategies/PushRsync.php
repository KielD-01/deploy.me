<?php

namespace App\Deploy\Delivery\Strategies;

use App\Deploy\Delivery\DeliveryStrategy;
use App\Deploy\Delivery\Verdict;
use App\Enums\DeliveryStrategyKey;
use App\Enums\ProjectSource;
use App\Models\Project;
use App\Platform\Git\GitRunner;
use App\Platform\Git\LocalRepository;
use App\Platform\Local\ProjectRoots;
use App\Support\Format;

/**
 * Ships the folder on the Mac as it is, uncommitted changes included, minus
 * what .gitignore leaves out.
 */
final class PushRsync implements DeliveryStrategy
{
    public function __construct(
        private readonly GitRunner $git,
        private readonly ProjectRoots $roots,
    ) {}

    public function key(): DeliveryStrategyKey
    {
        return DeliveryStrategyKey::PushRsync;
    }

    public function evaluate(Project $project, ?string $commit): Verdict
    {
        if ($project->source !== ProjectSource::Local) {
            return Verdict::notApplicable('Only for projects imported from a local folder.');
        }

        if ($commit !== null) {
            return Verdict::notApplicable('It uploads the folder as it is, not a particular commit.');
        }

        $path = $this->roots->resolve((string) $project->local_path);

        if ($path === null) {
            return Verdict::notApplicable(Format::homePath($project->local_path).' isn’t there.');
        }

        // The commit it's based on, for the record.
        $head = null;
        $message = null;
        if (LocalRepository::isRepository($path)) {
            $log = $this->git->run($path, ['log', '-1', '--format=%H%x1f%s'], timeout: 30);
            if ($log->successful() && str_contains($log->output(), "\x1f")) {
                [$head, $message] = explode("\x1f", trim($log->output()), 2);
            }
        }

        return Verdict::applicable(
            Format::homePath($project->local_path).' as it is now'.($head !== null ? ', based on '.substr($head, 0, 7) : '').'.',
            $head,
            $message,
        );
    }
}
