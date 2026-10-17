<?php

namespace App\Deploy\Delivery;

use App\Models\Credential;
use App\Models\Project;
use App\Platform\Git\GitRunner;
use App\Platform\GitHub\GitHubClient;
use App\Platform\GitHub\GitHubException;
use App\Support\BranchCommits;

/**
 * Whether a commit is on the project's branch: its head, or an ancestor of
 * it. The GitHub mirror holds every ref (it's a `clone --mirror`), so a sha
 * it knows can be an old commit or another branch's. Runs nobody picked a
 * commit for by hand — an agent over MCP, CI's automatic deploys — only
 * ship commits on the branch; a person's deploy can pick any commit.
 */
final class CommitOnBranch
{
    // The triggers held to the branch.
    public const GUARDED = ['mcp', 'ci'];

    public function __construct(private readonly GitRunner $git) {}

    public static function guards(string $trigger): bool
    {
        return in_array($trigger, self::GUARDED, true);
    }

    /**
     * Why a run started by $trigger can't ship $sha, or null when it can.
     */
    public function refusal(Project $project, string $sha, string $trigger): ?string
    {
        if (! self::guards($trigger)) {
            return null;
        }

        $short = substr($sha, 0, 7);
        $who = $trigger === 'ci' ? 'CI' : 'an agent';

        return match ($this->check($project, $sha)) {
            true => null,
            false => "Commit {$short} isn’t on {$project->branch}, and a deploy {$who} starts ships only commits on the project’s branch. To ship it anyway, pick it in the deploy dialog.",
            null => "Couldn’t tell whether commit {$short} is on {$project->branch} (neither the repository here nor GitHub said), so it wasn’t deployed. To ship it anyway, pick it in the deploy dialog.",
        };
    }

    /**
     * True when $sha is on the branch, false when it isn't, null when
     * neither the repository here nor GitHub could tell.
     */
    public function check(Project $project, string $sha): ?bool
    {
        if (preg_match('/^[0-9a-f]{7,40}$/', $sha) !== 1) {
            return false;
        }

        $repository = BranchCommits::repository($project);
        $local = $repository !== null ? $this->fromGit($repository, $project->branch, $sha) : null;

        // The mirror is as old as the last deploy: a commit it doesn't have
        // on the branch yet may be there on GitHub.
        if ($local === true || ! $project->hasGitHub()) {
            return $local;
        }

        return $this->fromGitHub($project, $sha) ?? $local;
    }

    private function fromGit(string $repository, string $branch, string $sha): ?bool
    {
        $head = $this->git->run($repository, ['rev-parse', '--verify', '--quiet', '--end-of-options', $branch.'^{commit}'], timeout: 30);

        if (! $head->successful()) {
            return null;
        }

        $ancestor = $this->git->run($repository, ['merge-base', '--is-ancestor', $sha, trim($head->output())], timeout: 30);

        // 1: not an ancestor; anything else: git doesn't know the commit.
        return match ($ancestor->exitCode()) {
            0 => true,
            1 => false,
            default => null,
        };
    }

    private function fromGitHub(Project $project, string $sha): ?bool
    {
        $credential = $project->credential ?? Credential::forOwner((string) $project->github_owner);

        if ($credential === null) {
            return null;
        }

        try {
            $github = GitHubClient::for($credential);
            $head = $github->branchHead((string) $project->github_owner, (string) $project->github_repo, $project->branch);

            if ($head === null) {
                return null;
            }

            if (str_starts_with($head, $sha)) {
                return true;
            }

            // $sha against the head: behind it (or the head itself) is on the branch.
            $status = $github->compare((string) $project->github_owner, (string) $project->github_repo, $head, $sha);
        } catch (GitHubException) {
            return null;
        }

        return match ($status) {
            'identical', 'behind' => true,
            'ahead', 'diverged' => false,
            default => null,
        };
    }
}
