<?php

namespace App\Livewire\Projects;

use App\Actions\Projects\PollCi;
use App\Deploy\Compose;
use App\Deploy\Delivery\DeliveryResolver;
use App\Deploy\Delivery\ImagePlan;
use App\Enums\Activation;
use App\Enums\AuditAction;
use App\Enums\ProjectSource;
use App\Jobs\PollCiStatus;
use App\Livewire\Concerns\ConfirmsIdentity;
use App\Models\AuditEntry;
use App\Models\Credential;
use App\Models\Project;
use App\Platform\Detection\Detection;
use App\Platform\Detection\GitHubInspector;
use App\Platform\Detection\LocalInspector;
use App\Platform\GitHub\GitHubClient;
use App\Platform\GitHub\GitHubException;
use App\Platform\Local\ProjectRoots;
use App\Support\Audit;
use App\Support\Format;
use App\Support\GreenBuild;
use App\Support\Names;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

class Show extends Component
{
    use ConfirmsIdentity;

    public const TABS = ['targets' => 'Targets', 'releases' => 'Releases', 'environment' => 'Environment', 'hooks' => 'Hooks', 'settings' => 'Settings'];

    public Project $project;

    #[Url]
    public string $tab = 'targets';

    public string $name = '';

    public string $branch = '';

    public bool $versionable = true;

    public int $keepReleases = 5;

    // One per line.
    public string $sharedPaths = '';

    public bool $dockerized = false;

    public string $stackName = '';

    public string $composeFile = '';

    public string $migrateService = '';

    // Empty: the role in DB_USERNAME. Otherwise an env key or a role name.
    public string $backupRole = '';

    // Untyped: a cleared number field arrives as an empty string.
    public $backupKeep = 5;

    /** @var list<string> */
    public array $appServices = [];

    /** @var list<array{key: string, enabled: bool}> */
    public array $strategies = [];

    /** @var list<array{var: string, kind: string, image: string, tag: string}> */
    public array $imageTemplates = [];

    public bool $waitForCi = true;

    public bool $autoDeploy = false;

    // "Test resolution": how each strategy answers, with the saved settings.
    public ?array $resolutionTrace = null;

    public string $healthUrl = '';

    // The project's own last word on a release: a command run on the server.
    public string $healthCommand = '';

    // Untyped: a cleared number field arrives as an empty string.
    public $healthTimeout = 180;

    public bool $autoRollback = true;

    public bool $blueGreen = false;

    public string $activation = 'builtin';

    public string $activationScript = '';

    public $credentialId = '';

    public ?string $notice = null;

    public ?string $problem = null;

    public function mount(Project $project): void
    {
        abort_if($project->archived_at !== null, 404);

        $this->project = $project;
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'targets';
        }
        $this->fillSettings();
    }

    // CI polls update the badge while the page is open.
    #[On('echo-private:projects.{project.id},.ci.updated')]
    public function refreshCi(): void
    {
        $this->project->refresh();
    }

    public function checkCi(PollCi $poll): void
    {
        $poll($this->project);
        $this->project->refresh();
    }

    /**
     * wire:sort handler: $key was dropped at $position.
     */
    public function sortStrategy(string $key, int $position): void
    {
        $list = collect(Project::normalizeStrategies($this->strategies));
        $item = $list->firstWhere('key', $key);

        if ($item === null) {
            return;
        }

        $list = $list->reject(fn (array $strategy) => $strategy['key'] === $key)->values();
        $list->splice(max(0, min($position, $list->count())), 0, [$item]);
        $this->strategies = $list->values()->all();
    }

    /**
     * How each delivery strategy would answer for the branch's latest commit
     * right now, with the saved settings.
     */
    public function testResolution(DeliveryResolver $resolver): void
    {
        $this->resolutionTrace = $resolver->resolve($this->project->refresh())->trace;
    }

    public function addImageTemplate(): void
    {
        $this->imageTemplates[] = ['var' => '', 'kind' => 'tag', 'image' => '', 'tag' => 'sha-{sha7}'];
    }

    public function removeImageTemplate(int $index): void
    {
        unset($this->imageTemplates[$index]);
        $this->imageTemplates = array_values($this->imageTemplates);
    }

    /**
     * Back to what detection makes of the workflow and the compose file.
     */
    public function resetImageTemplates(): void
    {
        $this->imageTemplates = ImagePlan::detected($this->project);
    }

    public function toggleStrategy(string $key): void
    {
        $this->strategies = array_map(
            fn (array $strategy) => $strategy['key'] === $key ? ['key' => $key, 'enabled' => ! $strategy['enabled']] : $strategy,
            Project::normalizeStrategies($this->strategies),
        );
    }

    public function save(): void
    {
        $this->reset('notice', 'problem');

        $path = ['regex:/^(?!\/)(?!.*\.\.)[A-Za-z0-9._\/-]+$/'];
        $service = ['regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'];

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'branch' => ['required', 'string', 'max:200', 'regex:/^(?!-)[A-Za-z0-9._\/-]+$/'],
            'keepReleases' => ['required', 'integer', 'between:1,10'],
            'sharedPaths' => ['nullable', 'string', 'max:2000'],
            'stackName' => [Rule::requiredIf($this->dockerized), 'nullable', 'string', 'max:63', 'regex:'.Names::STACK_PATTERN],
            'composeFile' => [Rule::requiredIf($this->dockerized), 'nullable', 'string', 'max:200', ...$path],
            'migrateService' => ['nullable', 'string', 'max:100', ...$service],
            'backupRole' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z_][A-Za-z0-9_$-]*$/'],
            'backupKeep' => ['required', 'integer', 'between:1,50'],
            'appServices' => ['array'],
            'appServices.*' => ['string', 'max:100', ...$service],
            'healthUrl' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'healthCommand' => ['nullable', 'string', 'max:500'],
            'healthTimeout' => ['required', 'integer', 'between:10,1800'],
            'activation' => ['required', Rule::enum(Activation::class)],
            'activationScript' => [Rule::requiredIf($this->activation === Activation::Script->value), 'nullable', 'string', 'max:200', ...$path],
            'credentialId' => ['nullable', 'integer', Rule::exists('credentials', 'id')],
            'imageTemplates' => ['array', 'max:20'],
            'imageTemplates.*.var' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'imageTemplates.*.kind' => ['required', 'in:tag,image'],
            'imageTemplates.*.image' => ['required', 'string', 'max:300', 'regex:/^[A-Za-z0-9._\/${}:-]+$/'],
            'imageTemplates.*.tag' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9._{}-]+$/'],
        ], [
            'branch.regex' => 'That isn’t a branch name git accepts.',
            'stackName.regex' => 'Compose project names use lowercase letters, digits, dashes and underscores.',
            'composeFile.regex' => 'A path inside the project, like docker-compose.prod.yml.',
            'activationScript.regex' => 'A path inside the project, like scripts/deploy.sh.',
            'backupRole.regex' => 'An env key like DB_USERNAME, or a Postgres role name.',
            'imageTemplates.*.var.regex' => 'A compose variable name, like IMAGE_TAG.',
            'imageTemplates.*.image.regex' => 'An image like ghcr.io/{owner}/{repo}-api.',
            'imageTemplates.*.tag.regex' => 'A tag like sha-{sha7}.',
        ], [
            'keepReleases' => 'releases to keep',
            'stackName' => 'stack name',
            'composeFile' => 'compose file',
            'backupRole' => 'backup role',
            'backupKeep' => 'dumps kept',
            'healthUrl' => 'health check URL',
            'healthCommand' => 'health command',
            'healthTimeout' => 'health timeout',
            'activationScript' => 'deploy script',
            'imageTemplates.*.var' => 'variable',
            'imageTemplates.*.image' => 'image',
            'imageTemplates.*.tag' => 'tag',
        ]);

        if (! collect($this->strategies)->contains(fn ($strategy) => (bool) ($strategy['enabled'] ?? false))) {
            $this->addError('strategies', 'Switch on at least one way to deliver releases.');

            return;
        }

        $credential = $this->credentialId !== '' && $this->credentialId !== null ? Credential::query()->find((int) $this->credentialId) : null;
        if ($credential !== null && $this->project->hasGitHub() && ! $credential->covers($this->project->github_owner)) {
            $this->addError('credentialId', "That token doesn’t reach {$this->project->github_owner}.");

            return;
        }

        // What runs on the server as the ssh user, at the next deploy or
        // health check: changing it asks for a passkey, like a token does.
        $commands = [
            'health_command' => trim($this->healthCommand) !== '' ? trim($this->healthCommand) : null,
            'activation' => $this->activation,
            'activation_script' => $this->activationScript !== '' ? $this->activationScript : null,
        ];
        $stored = [
            'health_command' => $this->project->health_command,
            'activation' => $this->project->activation->value,
            'activation_script' => $this->project->activation_script,
        ];

        if ($commands !== $stored && ! $this->confirmIdentity(route('projects.show', ['project' => $this->project, 'tab' => 'settings']))) {
            return;
        }

        $branchChanged = $this->branch !== $this->project->branch;

        $this->project->fill([
            'name' => $this->name,
            'branch' => $this->branch,
            'versionable' => $this->versionable,
            'keep_releases' => $this->keepReleases,
            'shared_paths' => Project::normalizePaths(preg_split('/\R/', $this->sharedPaths) ?: []),
            'dockerized' => $this->dockerized,
            // Switching Docker off keeps the stack settings for switching it back on.
            'stack_name' => $this->dockerized ? $this->stackName : $this->project->stack_name,
            'compose_files' => $this->dockerized ? [$this->composeFile] : $this->project->compose_files,
            'migrate_service' => $this->migrateService !== '' ? $this->migrateService : null,
            'backup_role' => $this->backupRole !== '' ? $this->backupRole : null,
            'backup_keep' => (int) $this->backupKeep,
            'app_services' => array_values(array_unique($this->appServices)),
            'delivery_strategies' => Project::normalizeStrategies($this->strategies),
            'wait_for_ci' => $this->waitForCi,
            'auto_deploy' => $this->autoDeploy,
            'health_url' => $this->healthUrl !== '' ? $this->healthUrl : null,
            'health_command' => $commands['health_command'],
            'health_timeout' => (int) $this->healthTimeout,
            'auto_rollback' => $this->autoRollback,
            'blue_green' => $this->blueGreen,
            'activation' => $commands['activation'],
            'activation_script' => $commands['activation_script'],
            'credential_id' => $credential?->id,
        ]);

        // Following detection is the default: nothing is stored while the
        // image templates still say what detection would have said.
        $templates = Project::normalizeImageTemplates($this->imageTemplates);
        $this->project->image_templates = $templates === ImagePlan::detected($this->project) ? null : $templates;
        $this->project->save();

        if ($branchChanged && $this->project->hasGitHub()) {
            $this->project->forceFill(['ci_etag' => null, 'ci_checked_at' => null])->save();
            PollCiStatus::dispatch($this->project->id);
        }

        $this->fillSettings();
        $this->notice = 'Settings saved.';
    }

    /**
     * Reads the project again: new workflows, compose files, a GitHub remote
     * added since the import.
     */
    public function redetect(): void
    {
        $this->reset('notice', 'problem');
        $project = $this->project;

        try {
            if ($project->source === ProjectSource::Local) {
                $path = app(ProjectRoots::class)->resolve((string) $project->local_path);

                if ($path === null) {
                    $this->problem = Format::homePath($project->local_path).' isn’t there any more.';

                    return;
                }

                $data = app(LocalInspector::class)->inspect($path);
            } else {
                $credential = $project->credential ?? Credential::forOwner((string) $project->github_owner);

                if ($credential === null) {
                    $this->problem = "Add a GitHub token that reaches {$project->github_owner} first.";

                    return;
                }

                $data = app(GitHubInspector::class)->inspect(GitHubClient::for($credential), $project->github_owner, $project->github_repo, $project->branch);
            }
        } catch (GitHubException $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $github = $data['github'] ?? null;

        $project->update([
            'detection' => $data,
            'detected_at' => now(),
            'framework' => (new Detection($data))->frameworkLabel(),
            'github_owner' => $github['owner'] ?? $project->github_owner,
            'github_repo' => $github['repo'] ?? $project->github_repo,
            'git_remote' => $github['remote'] ?? $project->git_remote,
        ]);

        $this->notice = 'Detection refreshed.';
    }

    public function archive(): void
    {
        $this->project->update(['archived_at' => now()]);
        Audit::record(AuditAction::ProjectRemoved, [
            'subject' => $this->project->name,
            'project_id' => $this->project->id,
            'detail' => 'nothing on its servers changed',
        ]);

        $this->redirectRoute('projects.index', navigate: true);
    }

    private function fillSettings(): void
    {
        $project = $this->project;

        $this->name = $project->name;
        $this->branch = $project->branch;
        $this->versionable = $project->versionable;
        $this->keepReleases = $project->keep_releases;
        $this->sharedPaths = implode("\n", $project->sharedPaths());
        $this->dockerized = $project->dockerized;
        $this->stackName = (string) $project->stack_name;
        $this->composeFile = $project->compose_files[0] ?? ($project->detected()->primaryCompose()['file'] ?? '');
        $this->migrateService = (string) $project->migrate_service;
        $this->backupRole = (string) $project->backup_role;
        $this->backupKeep = $project->backup_keep;
        $this->appServices = $project->app_services ?? [];
        $this->strategies = Project::normalizeStrategies($project->delivery_strategies ?? []);
        $this->imageTemplates = $project->imageTemplates();
        $this->waitForCi = $project->wait_for_ci;
        $this->autoDeploy = $project->auto_deploy;
        $this->healthUrl = (string) $project->health_url;
        $this->healthCommand = (string) $project->health_command;
        $this->healthTimeout = $project->health_timeout;
        $this->autoRollback = $project->auto_rollback;
        $this->blueGreen = $project->blue_green;
        $this->activation = $project->activation->value;
        $this->activationScript = (string) $project->activation_script;
        $this->credentialId = (string) ($project->credential_id ?? '');
    }

    public function render(): View
    {
        $project = $this->project;
        $detection = $project->detected();
        $compose = collect($detection->composeFiles())->firstWhere('file', $this->composeFile) ?? $detection->primaryCompose();
        $targets = $project->targets()->active()->with(['server', 'currentRelease'])->get();

        return view('livewire.projects.show', [
            'detection' => $detection,
            'local' => $project->source === ProjectSource::Local,
            'raceWarnings' => array_values(array_filter($detection->ciDeploys(), fn (array $deploy) => $deploy['automatic'])),
            'targetCount' => $targets->count(),
            // A commit CI has passed on that no server here is running yet.
            'green' => GreenBuild::for($project, $targets),
            'trail' => AuditEntry::query()->where('project_id', $project->id)->byHand()->orderByDesc('occurred_at')->orderByDesc('id')->limit(8)->get(),
            'envCount' => $project->envFiles()->count(),
            'hookCount' => $project->hooks()->count(),
            'composeFiles' => array_values(array_unique(array_filter([...array_column($detection->composeFiles(), 'file'), ...($project->compose_files ?? [])]))),
            'services' => array_values(array_unique([...($compose['services'] ?? []), ...$this->appServices])),
            'rollable' => Compose::rollable($project),
            'credentials' => Credential::query()->orderBy('login')->get()
                ->filter(fn (Credential $credential) => $project->github_owner === null || $credential->covers($project->github_owner))
                ->values(),
            'tabs' => self::TABS,
        ])->title($project->name);
    }
}
