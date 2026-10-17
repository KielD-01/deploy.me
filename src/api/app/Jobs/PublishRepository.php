<?php

namespace App\Jobs;

use App\Actions\Projects\PublishToGitHub;
use App\Models\Credential;
use App\Platform\Git\GitFailed;
use App\Platform\GitHub\GitHubException;
use App\Support\JobStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Creates a GitHub repository for a local folder and pushes to it, or pushes
 * to the GitHub remote it has. Progress goes to JobStatus for the page.
 */
class PublishRepository implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // A large first push can take a while, so it runs with the deploys
    // (whose worker allows an hour).
    public int $timeout = 1800;

    /**
     * @param  string|null  $owner  with $name: create owner/name on GitHub first; null: push to $remote
     */
    public function __construct(
        public string $statusId,
        public string $path,
        public int $credentialId,
        public ?string $owner = null,
        public ?string $name = null,
        public bool $private = true,
        public ?string $remote = null,
    ) {
        $this->onConnection('redis-deploys')->onQueue('deploys');
    }

    public function handle(PublishToGitHub $publish): void
    {
        $credential = Credential::query()->find($this->credentialId);

        if ($credential === null) {
            JobStatus::fail($this->statusId, 'That GitHub token was removed.');

            return;
        }

        $step = fn (string $step) => JobStatus::step($this->statusId, $step);

        try {
            $result = $this->owner !== null
                ? $publish->create($this->path, $credential, $this->owner, (string) $this->name, $this->private, $step)
                : $publish->push($this->path, (string) $this->remote, $credential, $step);
        } catch (GitHubException|GitFailed $e) {
            JobStatus::fail($this->statusId, $e->getMessage());

            return;
        } catch (Throwable $e) {
            JobStatus::fail($this->statusId, 'Publishing stopped unexpectedly. The worker log has the details.');

            throw $e;
        }

        JobStatus::finish($this->statusId, $result);
    }
}
