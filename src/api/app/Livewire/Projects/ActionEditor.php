<?php

namespace App\Livewire\Projects;

use App\Deploy\Actions\ActionDefinition;
use App\Deploy\Actions\ActionParser;
use App\Enums\AuditAction;
use App\Enums\HookRunIn;
use App\Livewire\Concerns\ConfirmsIdentity;
use App\Models\Project;
use App\Models\ProjectAction;
use App\Models\Target;
use App\Support\Audit;
use Cron\CronExpression;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\Yaml\Yaml;

/**
 * One action: made here, it's edited on this page (what runs on a server
 * changes only after a passkey, so the page asks for one before it opens);
 * from the repository, it's shown as it was read, with its runs.
 */
class ActionEditor extends Component
{
    use ConfirmsIdentity;

    public Project $project;

    #[Locked]
    public ?int $actionId = null;

    public string $name = '';

    public string $description = '';

    /** @var list<array{name: string, run: string, run_in: string, service: string, timeout: string, continue_on_error: bool, env_file: bool, if: string}> */
    public array $steps = [];

    /** @var list<array{name: string, type: string, options: string, default: string, description: string}> */
    public array $inputs = [];

    public bool $manual = true;

    public bool $scheduled = false;

    public string $schedule = '0 3 * * *';

    public string $scheduleTarget = '';

    public bool $afterDeploy = false;

    public string $afterDeployOn = 'succeeded';

    /** @var list<string> server slugs; all of them means every target */
    public array $targets = [];

    public bool $passkey = false;

    public bool $mcp = false;

    public bool $alert = true;

    /** @var list<string> */
    public array $problems = [];

    public ?string $yaml = null;

    public function mount(Project $project, ?ProjectAction $action = null): void
    {
        abort_if($project->archived_at !== null, 404);
        abort_if($action !== null && $action->project_id !== $project->id, 404);

        $this->project = $project;
        $this->actionId = $action?->id;

        // What an action made here runs changes only with a passkey: asked
        // before the form, so nothing typed into it is lost on the way.
        if (($action === null || ! $action->fromRepo()) && ! $this->confirmIdentity(request()->fullUrl())) {
            return;
        }

        $this->targets = $this->servers();

        if ($action?->definition() !== null) {
            $this->fillFrom($action->definition());
        } elseif ($action === null) {
            $this->steps = [$this->blankStep()];
            $this->scheduleTarget = $this->servers()[0] ?? '';
        }
    }

    public function action(): ?ProjectAction
    {
        return $this->actionId !== null ? ProjectAction::query()->find($this->actionId) : null;
    }

    public function addStep(): void
    {
        $this->steps[] = $this->blankStep();
    }

    public function removeStep(int $index): void
    {
        unset($this->steps[$index]);
        $this->steps = array_values($this->steps);
    }

    public function moveStep(int $index, int $direction): void
    {
        $to = $index + ($direction < 0 ? -1 : 1);

        if (isset($this->steps[$index], $this->steps[$to])) {
            [$this->steps[$index], $this->steps[$to]] = [$this->steps[$to], $this->steps[$index]];
        }
    }

    public function addInput(): void
    {
        $this->inputs[] = ['name' => '', 'type' => 'text', 'options' => '', 'default' => '', 'description' => ''];
    }

    public function removeInput(int $index): void
    {
        unset($this->inputs[$index]);
        $this->inputs = array_values($this->inputs);
    }

    /**
     * The form as a .deployme/actions file, to copy into the repository.
     */
    public function showYaml(): void
    {
        $definition = $this->check();
        $this->yaml = $definition !== null ? Yaml::dump($definition->toYaml(), 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK) : null;
    }

    public function closeYaml(): void
    {
        $this->yaml = null;
    }

    public function save(): void
    {
        $action = $this->action();

        if ($action?->fromRepo()) {
            return;
        }

        if (! $this->confirmIdentity($action !== null ? route('projects.actions.show', [$this->project, $action]) : route('projects.actions.create', $this->project))) {
            return;
        }

        $definition = $this->check();

        if ($definition === null) {
            return;
        }

        $attributes = ['name' => $definition->name(), 'definition' => $definition->data, 'errors' => null];

        if ($action === null || ($action->definition['schedule'] ?? null) !== $definition->schedule()) {
            $attributes['scheduled_through'] = now();
        }

        if ($action === null) {
            $action = ProjectAction::query()->create([
                ...$attributes,
                'project_id' => $this->project->id,
                'source' => ProjectAction::APP,
                'key' => ProjectAction::uniqueKey($this->project, $definition->name()),
            ]);
            Audit::record(AuditAction::ActionCreated, ['subject' => $action->name, 'project_id' => $this->project->id, 'detail' => $this->summary($definition)]);
        } else {
            $action->update($attributes);
            Audit::record(AuditAction::ActionUpdated, ['subject' => $action->name, 'project_id' => $this->project->id, 'detail' => $this->summary($definition)]);
        }

        session()->flash('notice', "Saved {$action->name}.");
        $this->redirectRoute('projects.show', ['project' => $this->project, 'tab' => 'actions'], navigate: true);
    }

    /**
     * The form through the same checks a repo file goes through. Null, with
     * the errors listed, when it doesn't pass.
     */
    private function check(): ?ActionDefinition
    {
        [$definition, $errors] = ActionParser::array($this->raw());
        $errors = [...$errors, ...($definition?->problems($this->project) ?? [])];
        $this->problems = $errors;

        return $errors === [] ? $definition : null;
    }

    /**
     * The form in the YAML schema's shape.
     *
     * @return array<string, mixed>
     */
    private function raw(): array
    {
        $inputs = [];
        foreach ($this->inputs as $input) {
            $type = $input['type'];
            $default = trim($input['default']);
            $inputs[trim($input['name'])] = array_filter([
                'type' => $type,
                'options' => $type === 'choice' ? array_values(array_filter(array_map('trim', explode(',', $input['options'])), fn (string $option) => $option !== '')) : null,
                'default' => match (true) {
                    $default === '' => null,
                    $type === 'boolean' => in_array(strtolower($default), ['1', 'yes', 'true', 'y', 'on'], true),
                    $type === 'number' && preg_match('/^-?\d+$/', $default) === 1 => (int) $default,
                    default => $default,
                },
                'description' => trim($input['description']) !== '' ? trim($input['description']) : null,
            ], fn ($value) => $value !== null);
        }

        $steps = array_map(function (array $step) {
            $service = trim($step['service']);

            return array_filter([
                'name' => trim($step['name']) !== '' ? trim($step['name']) : null,
                'exec' => $step['run_in'] === HookRunIn::ServiceExec->value ? $service : null,
                'one-off' => $step['run_in'] === HookRunIn::ServiceRun->value ? $service : null,
                'if' => $step['if'] !== '' ? "inputs.{$step['if']}" : null,
                'run' => $step['run'],
                'timeout' => trim($step['timeout']) !== '' ? (ctype_digit(trim($step['timeout'])) ? (int) $step['timeout'] : trim($step['timeout'])) : null,
                'continue-on-error' => $step['continue_on_error'] ?: null,
                'env-file' => $step['env_file'] ?: null,
            ], fn ($value) => $value !== null);
        }, $this->steps);

        $all = $this->servers();
        $chosen = array_values(array_intersect($all, $this->targets));

        return array_filter([
            'name' => $this->name,
            'description' => trim($this->description) !== '' ? $this->description : null,
            'on' => array_filter([
                'manual' => $this->manual,
                'schedule' => $this->scheduled ? $this->schedule : null,
                'target' => $this->scheduled && $this->scheduleTarget !== '' ? $this->scheduleTarget : null,
                'after_deploy' => $this->afterDeploy ? $this->afterDeployOn : null,
            ], fn ($value) => $value !== null),
            'inputs' => $inputs !== [] ? $inputs : null,
            'steps' => $steps,
            // Every server ticked is every server, now and later.
            'targets' => $chosen === $all ? null : $chosen,
            'confirm' => $this->passkey ? 'passkey' : null,
            'mcp' => $this->mcp,
            'alert' => $this->alert,
        ], fn ($value) => $value !== null);
    }

    private function fillFrom(ActionDefinition $definition): void
    {
        $this->name = $definition->name();
        $this->description = (string) $definition->description();
        $this->manual = $definition->manual();
        $this->scheduled = $definition->schedule() !== null;
        $this->schedule = $definition->schedule() ?? $this->schedule;
        $this->scheduleTarget = $definition->scheduledTarget($this->project)?->onServer()->slug ?? (string) $definition->scheduleTarget();
        $this->afterDeploy = $definition->afterDeploy() !== null;
        $this->afterDeployOn = $definition->afterDeploy() ?? 'succeeded';
        $this->targets = $definition->allowedTargets($this->project)->map(fn (Target $target) => $target->onServer()->slug)->values()->all();
        $this->passkey = $definition->passkey();
        $this->mcp = $definition->mcp();
        $this->alert = $definition->alert();

        $this->steps = array_map(fn (array $step) => [
            'name' => $step['name'],
            'run' => $step['run'],
            'run_in' => $step['run_in'],
            'service' => (string) $step['service'],
            'timeout' => ActionDefinition::duration($step['timeout']),
            'continue_on_error' => $step['continue_on_error'],
            'env_file' => $step['env_file'],
            'if' => (string) $step['if'],
        ], $definition->steps());

        $this->inputs = array_map(fn (array $input) => [
            'name' => $input['name'],
            'type' => $input['type'],
            'options' => implode(', ', $input['options']),
            'default' => match (true) {
                is_bool($input['default']) => $input['default'] ? 'yes' : 'no',
                default => (string) $input['default'],
            },
            'description' => (string) $input['description'],
        ], $definition->inputs());
    }

    /**
     * @return array{name: string, run: string, run_in: string, service: string, timeout: string, continue_on_error: bool, env_file: bool, if: string}
     */
    private function blankStep(): array
    {
        $service = $this->project->dockerized ? (string) ($this->project->app_services[0] ?? '') : '';

        return [
            'name' => '',
            'run' => '',
            'run_in' => $service !== '' ? HookRunIn::ServiceExec->value : HookRunIn::Host->value,
            'service' => $service,
            'timeout' => '10m',
            'continue_on_error' => false,
            'env_file' => false,
            'if' => '',
        ];
    }

    /**
     * The servers the project is on, by slug.
     *
     * @return list<string>
     */
    private function servers(): array
    {
        return $this->projectTargets()->map(fn (Target $target) => $target->onServer()->slug)->values()->all();
    }

    /**
     * @return Collection<int, Target>
     */
    private function projectTargets(): Collection
    {
        return Target::query()->active()->where('project_id', $this->project->id)->with('server')->orderBy('id')->get();
    }

    private function summary(ActionDefinition $definition): string
    {
        $steps = count($definition->steps());

        return "{$steps} ".Str::plural('step', $steps).' · '.implode(', ', array_filter([
            $definition->manual() ? 'manual' : null,
            $definition->scheduleLabel(),
            $definition->afterDeployLabel(),
        ]));
    }

    public function render(): View
    {
        $action = $this->action();
        $definition = $action?->definition();
        $project = $this->project;

        return view('livewire.projects.action-editor', [
            'action' => $action,
            'readOnly' => $action?->fromRepo() ?? false,
            'definition' => $definition,
            'repoYaml' => $action?->fromRepo() && $definition !== null ? Yaml::dump($definition->toYaml(), 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK) : null,
            'runs' => $action?->runs()->with(['server', 'user'])->limit(12)->get() ?? collect(),
            'projectTargets' => $this->projectTargets(),
            'services' => array_values(array_unique([...$project->detected()->composeServices(), ...($project->app_services ?? [])])),
            'runIns' => HookRunIn::cases(),
            'booleans' => array_values(array_filter(array_map(fn (array $input) => $input['type'] === 'boolean' ? trim($input['name']) : null, $this->inputs))),
            'next' => $this->scheduled && ($cron = trim($this->schedule)) !== '' && CronExpression::isValidExpression($cron)
                ? (new ActionDefinition(['schedule' => $cron]))->nextRun() : null,
            'zone' => ActionDefinition::timezone()->getName(),
        ])->title(($action !== null ? $action->name : 'New action').' · '.$project->name);
    }
}
