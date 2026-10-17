<?php

namespace App\Deploy\Delivery\Strategies;

use App\Deploy\Delivery\DeliveryStrategy;
use App\Deploy\Delivery\Verdict;
use App\Enums\DeliveryStrategyKey;
use App\Models\Credential;
use App\Models\Project;
use App\Platform\GitHub\GitHubClient;
use App\Platform\GitHub\GitHubException;

/**
 * The server fetches the commit itself, into a mirror in .deploy/, with its
 * own access to the repository (a deploy key, as a rule).
 */
final class ServerGit implements DeliveryStrategy
{
    public function key(): DeliveryStrategyKey
    {
        return DeliveryStrategyKey::ServerGit;
    }

    public function evaluate(Project $project, ?string $commit): Verdict
    {
        $remote = self::remote($project);

        if ($remote === null) {
            return Verdict::notApplicable('Needs a git remote the server can fetch from.');
        }

        $where = $project->githubFullName() ?? $remote;

        if ($commit !== null) {
            return Verdict::applicable('The server fetches '.substr($commit, 0, 7)." from {$where} itself.", $commit);
        }

        // The branch head, so the run page says what it'll get.
        $head = null;
        if ($project->hasGitHub() && ($credential = $project->credential ?? Credential::forOwner((string) $project->github_owner)) !== null) {
            try {
                $head = GitHubClient::for($credential)->commits((string) $project->github_owner, (string) $project->github_repo, $project->branch)['head'];
            } catch (GitHubException) {
                $head = null;
            }
        }

        return Verdict::applicable(
            "The server fetches the latest on {$project->branch} from {$where} itself. It needs a deploy key there.",
            $head['sha'] ?? null,
            $head['subject'] ?? null,
        );
    }

    /**
     * What the server fetches from: the project's remote, with GitHub's https
     * form turned into ssh, which a deploy key answers.
     */
    public static function remote(Project $project): ?string
    {
        $remote = (string) $project->git_remote;

        if ($project->hasGitHub() && ($remote === '' || str_starts_with($remote, 'https://github.com/'))) {
            return "git@github.com:{$project->github_owner}/{$project->github_repo}.git";
        }

        return $remote !== '' ? $remote : null;
    }
}
