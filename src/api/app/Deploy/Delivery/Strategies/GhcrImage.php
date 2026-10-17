<?php

namespace App\Deploy\Delivery\Strategies;

use App\Deploy\Delivery\DeliveryStrategy;
use App\Deploy\Delivery\ImagePlan;
use App\Deploy\Delivery\Verdict;
use App\Deploy\Delivery\VerdictState;
use App\Enums\DeliveryStrategyKey;
use App\Models\Credential;
use App\Models\Project;
use App\Platform\GitHub\CiStatus;
use App\Platform\GitHub\GhcrClient;
use App\Platform\GitHub\GitHubClient;
use App\Platform\GitHub\GitHubException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Ships the images CI published for the commit: its workflow run passed and
 * ghcr.io has every image the compose file pins. While the run is still
 * going, it's pending: the deploy waits for it.
 */
final class GhcrImage implements DeliveryStrategy
{
    // A deploy that waits asks again every 20 s; answers last that long.
    private const REMEMBER = 15;

    public function key(): DeliveryStrategyKey
    {
        return DeliveryStrategyKey::GhcrImage;
    }

    public function evaluate(Project $project, ?string $commit): Verdict
    {
        if (! $project->dockerized) {
            return Verdict::notApplicable('Only for Docker projects.');
        }

        $publishing = $project->detected()->imagePublishing();
        if ($publishing === null) {
            return Verdict::notApplicable('No workflow pushes an image to ghcr.io.');
        }

        if (! $project->hasGitHub()) {
            return Verdict::notApplicable('Needs the repository on GitHub, where CI builds the images.');
        }

        $credential = $project->credential ?? Credential::forOwner((string) $project->github_owner);
        if ($credential === null) {
            return Verdict::notApplicable("No GitHub token reaches {$project->github_owner} to check CI and pull the images.");
        }

        try {
            $github = GitHubClient::for($credential);
            $sha = $commit ?? ($github->commits((string) $project->github_owner, (string) $project->github_repo, $project->branch)['head']['sha'] ?? null);
        } catch (GitHubException $e) {
            return Verdict::error('GitHub didn’t answer: '.$e->getMessage());
        }

        if ($sha === null) {
            return Verdict::notApplicable("The branch {$project->branch} has no commits on GitHub.");
        }

        $plan = ImagePlan::for($project, $sha);
        if ($plan === null) {
            return Verdict::notApplicable('The compose file doesn’t take the image tag from a variable, so a release can’t be pinned to a commit’s images.');
        }

        $key = "deployme:ghcr:{$project->id}:{$sha}:".md5(serialize($plan->pins));

        if (($remembered = Cache::get($key)) instanceof Verdict) {
            return $remembered;
        }

        $verdict = $this->judge($project, $github, GhcrClient::for($credential), $plan, $sha);

        // A call that failed says nothing worth remembering: the next look asks again.
        if ($verdict->state !== VerdictState::Error) {
            Cache::put($key, $verdict, self::REMEMBER);
        }

        return $verdict;
    }

    private function judge(Project $project, GitHubClient $github, GhcrClient $ghcr, ImagePlan $plan, string $sha): Verdict
    {
        $sha7 = substr($sha, 0, 7);

        try {
            $runs = $github->runsForCommit((string) $project->github_owner, (string) $project->github_repo, $sha);
        } catch (GitHubException $e) {
            return Verdict::error('GitHub didn’t answer: '.$e->getMessage());
        }

        $run = collect($runs)->first(fn (array $run) => basename((string) ($run['path'] ?? '')) === $plan->workflow);
        $summary = $run !== null ? CiStatus::summary($run) : null;
        $message = $run !== null ? (string) strtok((string) ($run['head_commit']['message'] ?? $run['display_title'] ?? ''), "\n") : null;
        $label = $run !== null ? "{$plan->workflow} run #{$run['run_number']}" : $plan->workflow;

        if ($run !== null && ($run['status'] ?? 'completed') !== 'completed') {
            return Verdict::pending("{$label} for {$sha7} is still building images. The deploy starts by itself once they’re published.", $sha, $message, $summary);
        }

        if ($run !== null && ($run['conclusion'] ?? null) !== 'success') {
            return Verdict::notApplicable("{$label} for {$sha7} ".($run['conclusion'] === 'failure' ? 'failed' : 'ended as '.($run['conclusion'] ?? 'unknown')).'.');
        }

        $missing = [];
        $unconfirmed = 0;
        foreach ($plan->images as $image) {
            $exists = $ghcr->exists($image['image'], $image['tag']);
            if ($exists === false) {
                $missing[] = "{$image['image']}:{$image['tag']}";
            } elseif ($exists === null) {
                $unconfirmed++;
            }
        }

        if ($missing !== []) {
            return $run === null
                ? Verdict::pending("{$plan->workflow} hasn’t published images for {$sha7} yet.", $sha, $message)
                : Verdict::notApplicable("{$label} passed, but ghcr.io has no ".implode(', ', $missing).'.');
        }

        if ($run === null && $unconfirmed > 0) {
            return Verdict::pending("{$plan->workflow} hasn’t run for {$sha7} yet.", $sha, $message);
        }

        $count = count($plan->images);
        $found = $unconfirmed === $count
            ? 'ghcr.io wouldn’t confirm the images (the token needs read:packages)'
            : $count.' '.Str::plural('image', $count).' tagged '.$plan->images[0]['tag'].' found';

        return Verdict::applicable(($run !== null ? "{$label} passed for {$sha7} · " : '').$found.'.', $sha, $message, $summary);
    }
}
