<?php

namespace App\Livewire\Deployments;

use App\Actions\Deployments\CancelDeployment;
use App\Actions\Deployments\StartDeployment;
use App\Deploy\Conversion\Conversion;
use App\Deploy\Delivery\VerdictState;
use App\Deploy\Logging\LogArchive;
use App\Deploy\Pipeline\ResumePlanner;
use App\Deploy\ScriptRun;
use App\Enums\DeliveryStrategyKey;
use App\Enums\DeploymentKind;
use App\Enums\DeploymentStatus;
use App\Jobs\RunDeployment;
use App\Livewire\Concerns\ConfirmsIdentity;
use App\Livewire\Concerns\StartsRuns;
use App\Models\Credential;
use App\Models\Deployment;
use App\Models\Release;
use App\Platform\GitHub\GitHubClient;
use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * One run: its steps as they go, and the live log. The log itself lives in
 * the browser (Alpine, deploy-log.js); this component serves the lines it
 * missed. Once it's over: run it again, or switch back to the release it
 * replaced.
 */
class Show extends Component
{
    use ConfirmsIdentity, StartsRuns;

    public Deployment $deployment;

    public ?string $problem = null;

    public ?string $notice = null;

    public function mount(Deployment $deployment): void
    {
        $this->deployment = $deployment;
    }

    // Steps and status re-render as the runner reports them.
    #[On('echo-private:deployments.{deployment.id},.updated')]
    public function refreshRun(): void
    {
        $this->deployment->refresh();
    }

    public function cancel(CancelDeployment $cancel): void
    {
        $cancel($this->deployment);
        $this->deployment->refresh();
    }

    public function retry(StartDeployment $start): void
    {
        $run = $this->deployment;

        if (! $this->canRetry()) {
            return;
        }

        $options = $run->options ?? [];

        // The same commit again (an upload of the folder takes it as it is
        // now), with the same backup and migration choices.
        if (in_array($run->kind, [DeploymentKind::Deploy, DeploymentKind::Redeploy], true)) {
            $options = array_intersect_key($run->options ?? [], array_flip(['backup', 'skip_migrations']));

            if ($run->strategy === DeliveryStrategyKey::PushArchive->value && $run->commit_sha !== null) {
                $options['commit'] = $run->commit_sha;
            }
        }

        $this->startRun($start, $run->target, $run->kind, $options);
    }

    /**
     * Picks up where this deploy stopped, with the release it left on the
     * server: the retry skips the steps this run got done.
     */
    public function retryFrom(StartDeployment $start): void
    {
        $run = $this->deployment;
        $point = $this->canRetry() ? ResumePlanner::for($run) : null;

        if ($point === null) {
            return;
        }

        $this->startRun($start, $run->target, $run->kind, $run->options ?? [], 'retry', [
            'retry_of_id' => $run->id,
            'resume_from' => $point->key,
            'strategy' => $run->strategy,
            'commit_sha' => $run->commit_sha,
            'commit_message' => $run->commit_message,
        ]);
    }

    /**
     * A run waiting for CI goes ahead with the next strategy that applies.
     */
    public function deployNow(): void
    {
        $run = $this->deployment;
        $fallback = $this->fallback();

        if ($run->status !== DeploymentStatus::WaitingCi || $fallback === null) {
            return;
        }

        $run->forceFill(['options' => [...($run->options ?? []), 'strategy' => $fallback->value]])->save();
        RunDeployment::dispatch($run->id);
        $run->refresh();
    }

    public function rollBack(StartDeployment $start): void
    {
        if (($release = $this->rollbackTarget()) !== null) {
            $this->startRun($start, $this->deployment->target, DeploymentKind::Rollback, ['release_id' => $release->id]);
        }
    }

    /**
     * A converted folder goes back the way it was, while nothing has been
     * deployed since.
     */
    public function undoConversion(StartDeployment $start): void
    {
        if (($release = $this->convertedRelease()) !== null) {
            $this->startRun($start, $this->deployment->target, DeploymentKind::UndoConvert, ['release_id' => $release->id]);
        }
    }

    /**
     * The hard-link copy of the old layout a conversion kept. Removing files
     * on a server asks for a passkey first.
     */
    public function removeBackup(SshClient $ssh): void
    {
        $this->reset('problem', 'notice');
        $run = $this->deployment;
        $backup = $this->backup();

        if ($backup === null || ! $this->confirmIdentity(route('deployments.show', $run))) {
            return;
        }

        $result = $ssh->run($run->server->ssh_alias, RemoteScript::file('deploy-convert', Conversion::bare($run->target->path, $run->release->name, 'drop-backup')), timeout: 300);

        if (! $result->successful()) {
            $this->problem = 'Couldn’t remove the backup: '.(new ScriptRun($result, []))->failure('the server said no more.');

            return;
        }

        $run->forceFill(['options' => [...($run->options ?? []), 'backup_removed_at' => now()->toIso8601String()]])->save();
        $this->notice = "Removed {$backup}.";
    }

    private function canRetry(): bool
    {
        return in_array($this->deployment->status, [DeploymentStatus::Failed, DeploymentStatus::RolledBack, DeploymentStatus::Cancelled, DeploymentStatus::Stalled], true)
            // Breaking a lock asks for a passkey each time, on the Targets tab.
            && $this->deployment->kind !== DeploymentKind::Unlock
            && $this->deployment->target->archived_at === null;
    }

    /**
     * The release a successful deploy replaced, while switching back to it
     * still works.
     */
    private function rollbackTarget(): ?Release
    {
        $run = $this->deployment;

        if ($run->status !== DeploymentStatus::Succeeded
            || ! in_array($run->kind, [DeploymentKind::Deploy, DeploymentKind::Redeploy], true)
            || $run->target->current_release_id !== $run->release_id) {
            return null;
        }

        $previous = $run->previousRelease;

        return $previous?->canRollBackTo($run->target->project) ? $previous : null;
    }

    /**
     * The release a conversion made, while it's still the live one.
     */
    private function convertedRelease(): ?Release
    {
        $run = $this->deployment;

        if ($run->kind !== DeploymentKind::Convert || $run->status !== DeploymentStatus::Succeeded || $run->release_id === null) {
            return null;
        }

        return $run->target->current_release_id === $run->release_id ? $run->release : null;
    }

    /**
     * Where a finished conversion kept the old layout, until it's removed.
     */
    private function backup(): ?string
    {
        $run = $this->deployment;
        $options = $run->options ?? [];

        return $run->kind === DeploymentKind::Convert && $run->status === DeploymentStatus::Succeeded && isset($options['backup']) && ! isset($options['backup_removed_at'])
            ? (string) $options['backup']
            : null;
    }

    /**
     * What "deploy now" uses: the first strategy after the pending one that applies.
     */
    private function fallback(): ?DeliveryStrategyKey
    {
        $pendingSeen = false;

        foreach ($this->deployment->strategy_trace ?? [] as $entry) {
            if (($entry['state'] ?? null) === VerdictState::Pending->value) {
                $pendingSeen = true;
            } elseif ($pendingSeen && ($entry['state'] ?? null) === VerdictState::Applicable->value) {
                return DeliveryStrategyKey::tryFrom((string) $entry['key']);
            }
        }

        return null;
    }

    /**
     * The CI run a waiting deploy waits for, and its jobs.
     *
     * @return array{run: ?array<string, mixed>, jobs: list<array<string, mixed>>}|null
     */
    private function waitingFor(): ?array
    {
        $run = $this->deployment;

        if ($run->status !== DeploymentStatus::WaitingCi) {
            return null;
        }

        $ci = collect($run->strategy_trace ?? [])->firstWhere('state', VerdictState::Pending->value)['run'] ?? null;
        $project = $run->project;
        $credential = $project->credential ?? Credential::forOwner((string) $project->github_owner);

        if (! is_array($ci) || ! isset($ci['id']) || $credential === null || ! $project->hasGitHub()) {
            return ['run' => is_array($ci) ? $ci : null, 'jobs' => []];
        }

        $jobs = Cache::remember("deployme:ci-jobs:{$ci['id']}", 15, fn () => rescue(
            fn () => GitHubClient::for($credential)->runJobs((string) $project->github_owner, (string) $project->github_repo, (int) $ci['id']),
            [],
            report: false,
        ));

        return ['run' => $ci, 'jobs' => $jobs];
    }

    /**
     * Lines after $seq: the first load, a batch too big to broadcast, a reconnect.
     *
     * @return list<array{0: int, 1: string, 2: ?string, 3: string, 4: string}>
     */
    #[Renderless]
    public function linesAfter(int $seq): array
    {
        return $this->deployment->linesForPane($seq);
    }

    public function render(): View
    {
        $deployment = $this->deployment->loadMissing(['project', 'server', 'target', 'user', 'release', 'previousRelease', 'retryOf']);
        $retryOf = $deployment->retryOf;

        return view('livewire.deployments.show', [
            'steps' => $deployment->steps()->get(),
            'project' => $deployment->project,
            'server' => $deployment->server,
            'target' => $deployment->target,
            'strategy' => DeliveryStrategyKey::tryFrom((string) $deployment->strategy),
            'canRetry' => $this->canRetry(),
            'resume' => $this->canRetry() ? ResumePlanner::for($deployment) : null,
            'retryOf' => $retryOf,
            // The step it retries from, as the run it retries named it.
            'resumeLabel' => $retryOf !== null && $deployment->resume_from !== null
                ? ($retryOf->steps()->where('key', $deployment->resume_from)->value('label') ?? $deployment->resume_from)
                : null,
            'retriedAs' => $deployment->retries()->latest('id')->first(),
            'rollbackTo' => $this->rollbackTarget(),
            'undoable' => $this->convertedRelease(),
            'backup' => $this->backup(),
            'fallback' => $deployment->status === DeploymentStatus::WaitingCi ? $this->fallback() : null,
            'waiting' => $this->waitingFor(),
            // Its lines left the database for an archive (Settings → Schedules).
            'archived' => ! $deployment->isActive() && LogArchive::exists($deployment->id) && $deployment->logLines(0, 1)->isEmpty(),
        ])->title('Run '.$deployment->number());
    }
}
