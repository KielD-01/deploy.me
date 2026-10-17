<?php

namespace App\Livewire\Projects;

use App\Actions\Deployments\StartDeployment;
use App\Deploy\Delivery\DeliveryResolver;
use App\Deploy\Delivery\Resolution;
use App\Deploy\DeploymentBusy;
use App\Deploy\DeploymentRefused;
use App\Enums\CiState;
use App\Enums\DeploymentKind;
use App\Models\Credential;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Target;
use App\Platform\GitHub\CiStatus;
use App\Platform\GitHub\GitHubClient;
use App\Support\BranchCommits;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The deploy dialog: which targets, which commit, and the delivery strategy
 * that will ship it, with the reasons.
 */
class DeployDialog extends Component
{
    // How many recent commits the dialog offers.
    public const COMMITS = 12;

    #[Locked]
    public int $projectId;

    public bool $open = false;

    /** @var list<int> */
    public array $targetIds = [];

    // Empty: the branch's latest commit (or the folder as it is).
    // Only pickCommit() sets it: it goes into the preview's GitHub requests.
    #[Locked]
    public string $commit = '';

    // Docker projects that migrate: back up first, or skip the migrations.
    public bool $backup = true;

    public bool $skipMigrations = false;

    public ?string $problem = null;

    // One per opening of the dialog: what makes asking to deploy twice start nothing twice.
    #[Locked]
    public string $attempt = '';

    public function mount(int $projectId): void
    {
        $this->projectId = $projectId;
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()->findOrFail($this->projectId);
    }

    #[On('open-deploy-dialog')]
    public function openFor(?int $targetId = null, ?string $commit = null): void
    {
        $ids = $this->targetIds();

        $this->targetIds = $targetId !== null && in_array($targetId, $ids, true) ? [$targetId] : array_slice($ids, 0, 1);
        $this->pickCommit((string) $commit);
        $this->backup = true;
        $this->skipMigrations = false;
        $this->problem = null;
        $this->open = $ids !== [];
        $this->attempt = (string) Str::uuid();

        // The button that asked for the dialog spins until this reaches the page.
        $this->dispatch('deploy-dialog-opened');
    }

    public function close(): void
    {
        $this->reset('open', 'problem');
    }

    public function pickTarget(string $id): void
    {
        $ids = $this->targetIds();
        $this->targetIds = $id === 'all' ? $ids : array_values(array_intersect([(int) $id], $ids));
    }

    public function pickCommit(string $sha): void
    {
        $this->commit = preg_match('/^[0-9a-f]{7,40}$/', $sha) === 1 ? $sha : '';
    }

    /**
     * Starts a run on each picked target and says whether it's on its way to
     * the run page (the button stays busy until then). Idempotent within one
     * opening of the dialog: asked again with the same choices — a double
     * click, a request sent twice — it starts nothing on a target this attempt
     * already started a run on, and goes to the run it did start.
     */
    public function deploy(StartDeployment $start): bool
    {
        $this->reset('problem');

        if ($this->attempt === '') {
            $this->attempt = (string) Str::uuid();
        }

        $key = "deployme:deploy-dialog:{$this->attempt}:".md5((string) json_encode($this->options()));

        try {
            return Cache::lock("{$key}:lock", 60)->block(15, fn () => $this->startRuns($start, $key));
        } catch (LockTimeoutException) {
            $this->problem = 'The first click on Deploy is still starting these runs.';

            return false;
        }
    }

    private function startRuns(StartDeployment $start, string $key): bool
    {
        $targets = $this->project->targets()->active()->with(['server', 'project', 'currentRelease'])->whereKey($this->targetIds)->orderBy('id')->get();

        if ($targets->isEmpty()) {
            $this->problem = 'Pick where to deploy.';

            return false;
        }

        // Nothing starts while one of the picked targets can't take a deploy.
        $refused = $targets->reject(function (Target $target) use ($start) {
            try {
                $start->check($target, DeploymentKind::Deploy);

                return true;
            } catch (DeploymentRefused) {
                return false;
            }
        });

        if ($refused->isNotEmpty()) {
            $this->problem = DeploymentRefused::unconverted($refused)->getMessage();

            return false;
        }

        // Target id => the run this attempt started there, kept as each one starts.
        /** @var array<int, int> $runs */
        $runs = (array) Cache::get($key, []);
        $busy = [];

        foreach ($targets as $target) {
            if (isset($runs[$target->id])) {
                continue;
            }

            try {
                $runs[$target->id] = $start($target, DeploymentKind::Deploy, auth()->user(), $this->options())->id;
                Cache::put($key, $runs, now()->addHour());
            } catch (DeploymentBusy) {
                $busy[] = $target->server->name;
            } catch (DeploymentRefused $e) {
                // It changed since the check above.
                $this->problem = $e->getMessage();

                return false;
            }
        }

        $started = Deployment::query()->whereKey(array_values($runs))->orderBy('id')->get();

        if ($busy !== []) {
            $this->problem = implode(', ', $busy).(count($busy) === 1 ? ' is' : ' are').' busy with another run.'
                .($started->isNotEmpty() ? ' Started '.$started->map->number()->implode(', ').' on the rest.' : '');

            return false;
        }

        $first = $started->firstWhere('id', $runs[$targets->first()->id] ?? null) ?? $started->first();
        $this->redirectRoute('deployments.show', $first, navigate: true);

        return true;
    }

    /**
     * The branch's recent commits, newest first, and whether each adds a migration.
     *
     * @return list<array{sha: string, subject: string, date: ?Carbon, migration: bool}>
     */
    #[Computed]
    public function commits(): array
    {
        return BranchCommits::recent($this->project, self::COMMITS);
    }

    /**
     * What GitHub Actions says about each recent commit on the branch.
     *
     * @return array<string, CiState>
     */
    #[Computed]
    public function ci(): array
    {
        $project = $this->project;
        $credential = $project->hasGitHub() ? ($project->credential ?? Credential::forOwner((string) $project->github_owner)) : null;

        if ($credential === null) {
            return [];
        }

        $runs = Cache::remember("deployme:branch-runs:{$project->id}:{$project->branch}", 20, fn () => rescue(
            fn () => GitHubClient::for($credential)->workflowRuns((string) $project->github_owner, (string) $project->github_repo, $project->branch)['runs'],
            [],
            report: false,
        ));

        return collect($runs)
            ->groupBy('head_sha')
            ->map(fn (Collection $runs) => CiStatus::fromRuns($runs->values()->all())['state'])
            ->all();
    }

    #[Computed]
    public function resolution(): Resolution
    {
        return app(DeliveryResolver::class)->resolve($this->project, $this->commit !== '' ? $this->commit : null);
    }

    /**
     * What the dialog asks for; StartDeployment fills in the defaults (a
     * backup when the project can have one, the migrations run).
     *
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return array_filter([
            'commit' => $this->commit !== '' ? $this->commit : null,
            'backup' => $this->backup ? null : false,
            'skip_migrations' => $this->skipMigrations ? true : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * @return list<int>
     */
    private function targetIds(): array
    {
        return $this->project->targets()->active()->orderBy('id')->pluck('id')->all();
    }

    public function render(): View
    {
        if (! $this->open) {
            return view('livewire.projects.deploy-dialog', [
                'targets' => collect(), 'commits' => [], 'live' => [], 'since' => null, 'sinceMigrations' => 0, 'resolution' => null,
                'migrates' => false, 'canBackup' => false, 'ci' => [],
            ]);
        }

        /** @var Collection<int, Target> $targets */
        $targets = $this->project->targets()->active()->with(['server', 'currentRelease'])->orderBy('id')->get();
        $commits = $this->commits;

        // Which servers each commit is live on.
        $live = [];
        foreach ($targets as $target) {
            if (($sha = $target->currentRelease?->commit_sha) !== null) {
                $live[$sha][] = $target->server->name;
            }
        }

        // How far the first picked target is behind.
        $since = null;
        $sinceMigrations = 0;
        $liveSha = $targets->firstWhere('id', $this->targetIds[0] ?? null)?->currentRelease?->commit_sha;
        if ($liveSha !== null && ($index = collect($commits)->search(fn (array $commit) => $commit['sha'] === $liveSha)) !== false) {
            $since = $index;
            $sinceMigrations = collect($commits)->take($index)->where('migration', true)->count();
        }

        return view('livewire.projects.deploy-dialog', [
            'targets' => $targets,
            'commits' => $commits,
            'live' => $live,
            'since' => $since,
            'sinceMigrations' => $sinceMigrations,
            'resolution' => $this->resolution,
            'ci' => $this->ci,
            'migrates' => StartDeployment::migrates($this->project),
            'canBackup' => StartDeployment::canBackUp($this->project),
        ]);
    }
}
