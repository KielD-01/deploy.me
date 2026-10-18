<?php

namespace App\Deploy\Actions;

use App\Enums\HookRunIn;
use App\Models\Project;
use App\Models\Server;
use App\Models\Target;
use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * An action's checked definition, the one shape repo files and the editor
 * both end up in (ActionParser makes it): what runs, where, with which
 * inputs, when it runs by itself, and its safety switches.
 *
 * @phpstan-type Input array{name: string, type: string, options: list<string>, default: string|int|bool|null, description: ?string}
 * @phpstan-type ActionStep array{name: string, run: string, run_in: string, service: ?string, if: ?string, timeout: int, continue_on_error: bool, env_file: bool}
 */
final readonly class ActionDefinition
{
    public const INPUT_TYPES = ['text', 'number', 'boolean', 'choice', 'secret'];

    public const AFTER_DEPLOY = ['succeeded', 'failed', 'either'];

    // A step's own timeout when it names none, in seconds.
    public const DEFAULT_TIMEOUT = 600;

    /**
     * @param  array<string, mixed>  $data  the normalized form (ActionParser::normalize)
     */
    public function __construct(public array $data) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function of(?array $data): ?self
    {
        return $data === null ? null : new self($data);
    }

    public function name(): string
    {
        return (string) $this->data['name'];
    }

    public function description(): ?string
    {
        return $this->data['description'] ?? null;
    }

    public function manual(): bool
    {
        return (bool) ($this->data['manual'] ?? true);
    }

    public function schedule(): ?string
    {
        return $this->data['schedule'] ?? null;
    }

    public function scheduleTarget(): ?string
    {
        return $this->data['schedule_target'] ?? null;
    }

    public function afterDeploy(): ?string
    {
        return $this->data['after_deploy'] ?? null;
    }

    /**
     * "After a deploy succeeds", or null when it doesn't run after deploys.
     */
    public function afterDeployLabel(): ?string
    {
        return match ($this->afterDeploy()) {
            'succeeded' => 'after a deploy succeeds',
            'failed' => 'after a deploy fails',
            'either' => 'after every deploy',
            default => null,
        };
    }

    public function passkey(): bool
    {
        return (bool) ($this->data['passkey'] ?? false);
    }

    public function mcp(): bool
    {
        return (bool) ($this->data['mcp'] ?? false);
    }

    public function alert(): bool
    {
        return (bool) ($this->data['alert'] ?? true);
    }

    /**
     * The servers it may run on (slugs, names or ssh aliases), or null for
     * every server the project is on.
     *
     * @return list<string>|null
     */
    public function targets(): ?array
    {
        return $this->data['targets'] ?? null;
    }

    /**
     * @return list<Input>
     */
    public function inputs(): array
    {
        return $this->data['inputs'] ?? [];
    }

    /**
     * @return Input|null
     */
    public function input(string $name): ?array
    {
        return collect($this->inputs())->firstWhere('name', $name);
    }

    /**
     * @return list<ActionStep>
     */
    public function steps(): array
    {
        return $this->data['steps'] ?? [];
    }

    /**
     * The environment variable an input reaches the steps as: keep-days → KEEP_DAYS.
     */
    public static function envName(string $input): string
    {
        return strtoupper(str_replace('-', '_', $input));
    }

    /**
     * Where a step runs, as the plan and the run page show it: "exec api".
     *
     * @param  ActionStep  $step
     */
    public static function where(array $step): string
    {
        return match (HookRunIn::from($step['run_in'])) {
            HookRunIn::Host => 'on the server',
            HookRunIn::ServiceExec => "exec {$step['service']}",
            HookRunIn::ServiceRun => "one-off {$step['service']}",
        };
    }

    public static function duration(int $seconds): string
    {
        return match (true) {
            $seconds % 3600 === 0 => ($seconds / 3600).'h',
            $seconds % 60 === 0 => ($seconds / 60).'m',
            default => $seconds.'s',
        };
    }

    /**
     * The project's targets this action may run on.
     *
     * @return Collection<int, Target>
     */
    public function allowedTargets(Project $project): Collection
    {
        $targets = Target::query()->active()->where('project_id', $project->id)->with(['server', 'currentRelease', 'project'])->orderBy('id')->get();
        $names = $this->targets();

        return $names === null ? $targets : $targets->filter(fn (Target $target) => self::names($target->onServer(), $names))->values();
    }

    public function allows(Target $target): bool
    {
        $names = $this->targets();

        return $names === null || self::names($target->onServer(), $names);
    }

    /**
     * The target a schedule runs on: the one it names, or the only one it may run on.
     */
    public function scheduledTarget(Project $project): ?Target
    {
        $targets = $this->allowedTargets($project);
        $name = $this->scheduleTarget();

        if ($name === null) {
            return $targets->count() === 1 ? $targets->first() : null;
        }

        return $targets->first(fn (Target $target) => self::names($target->onServer(), [$name]));
    }

    /**
     * What doesn't fit the project as it is now: a target it isn't on, a
     * schedule with no target to run on.
     *
     * @return list<string>
     */
    public function problems(Project $project): array
    {
        $problems = [];
        $servers = Target::query()->active()->where('project_id', $project->id)->with('server')->get()->map(fn (Target $target) => $target->onServer());

        foreach ($this->targets() ?? [] as $name) {
            if (! $servers->contains(fn (Server $server) => self::names($server, [$name]))) {
                $problems[] = "targets: {$project->name} isn’t on a server called “{$name}”.";
            }
        }

        if ($this->schedule() !== null && $this->scheduledTarget($project) === null) {
            $problems[] = $this->scheduleTarget() !== null
                ? "on.target: the schedule runs on “{$this->scheduleTarget()}”, which isn’t one of the action’s targets."
                : 'on.target: name the server the schedule runs on (the action may run on more than one).';
        }

        return $problems;
    }

    /**
     * When the schedule is next due, in the scheduler's timezone.
     */
    public function nextRun(?Carbon $after = null): ?Carbon
    {
        $schedule = $this->schedule();

        if ($schedule === null) {
            return null;
        }

        $zone = self::timezone();

        return Carbon::instance((new CronExpression($schedule))->getNextRunDate(($after ?? now())->copy()->setTimezone($zone)))->setTimezone($zone);
    }

    /**
     * The schedule's latest time at or before $at, in UTC.
     */
    public function lastDue(Carbon $at): ?Carbon
    {
        $schedule = $this->schedule();

        if ($schedule === null) {
            return null;
        }

        return Carbon::instance((new CronExpression($schedule))->getPreviousRunDate($at->copy()->setTimezone(self::timezone()), 0, true))->utc();
    }

    /**
     * Schedules read as the machine's clock does (TZ, set by bin/configure).
     */
    public static function timezone(): DateTimeZone
    {
        $zone = (string) config('deployme.actions.timezone');

        return in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true) ? new DateTimeZone($zone) : new DateTimeZone('UTC');
    }

    /**
     * "Sundays 04:30", "daily 03:00", or the expression itself.
     */
    public function scheduleLabel(): ?string
    {
        $schedule = $this->schedule();

        if ($schedule === null) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($schedule)) ?: [];

        if (count($parts) === 5 && ctype_digit($parts[0]) && ctype_digit($parts[1]) && $parts[2] === '*' && $parts[3] === '*') {
            $time = sprintf('%02d:%02d', (int) $parts[1], (int) $parts[0]);
            $days = ['0' => 'Sundays', '1' => 'Mondays', '2' => 'Tuesdays', '3' => 'Wednesdays', '4' => 'Thursdays', '5' => 'Fridays', '6' => 'Saturdays', '7' => 'Sundays'];

            if ($parts[4] === '*') {
                return "daily {$time}";
            }

            if (isset($days[$parts[4]])) {
                return "{$days[$parts[4]]} {$time}";
            }
        }

        return $schedule;
    }

    /**
     * The definition in the YAML schema of .deployme/actions/*.yml, for
     * "Copy as YAML" (and a file to move an action made here into the repo).
     *
     * @return array<string, mixed>
     */
    public function toYaml(): array
    {
        $on = array_filter([
            'manual' => $this->manual(),
            'schedule' => $this->schedule(),
            'target' => $this->scheduleTarget(),
            'after_deploy' => $this->afterDeploy(),
        ], fn ($value) => $value !== null);

        $inputs = [];
        foreach ($this->inputs() as $input) {
            $inputs[$input['name']] = array_filter([
                'type' => $input['type'],
                'options' => $input['options'] !== [] ? $input['options'] : null,
                'default' => $input['default'],
                'description' => $input['description'],
            ], fn ($value) => $value !== null);
        }

        $steps = array_map(fn (array $step) => array_filter([
            'name' => $step['name'],
            'exec' => $step['run_in'] === HookRunIn::ServiceExec->value ? $step['service'] : null,
            'one-off' => $step['run_in'] === HookRunIn::ServiceRun->value ? $step['service'] : null,
            'if' => $step['if'] !== null ? "inputs.{$step['if']}" : null,
            'run' => $step['run'],
            'timeout' => $step['timeout'] !== self::DEFAULT_TIMEOUT ? self::duration($step['timeout']) : null,
            'continue-on-error' => $step['continue_on_error'] ?: null,
            'env-file' => $step['env_file'] ?: null,
        ], fn ($value) => $value !== null), $this->steps());

        return array_filter([
            'name' => $this->name(),
            'description' => $this->description(),
            'on' => $on,
            'inputs' => $inputs !== [] ? $inputs : null,
            'steps' => $steps,
            'targets' => $this->targets(),
            'confirm' => $this->passkey() ? 'passkey' : null,
            'mcp' => $this->mcp() ?: null,
            'alert' => $this->alert() ? null : false,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  list<string>  $names
     */
    private static function names(Server $server, array $names): bool
    {
        foreach ($names as $name) {
            if ($name === $server->slug || $name === $server->ssh_alias || mb_strtolower($name) === mb_strtolower($server->name)) {
                return true;
            }
        }

        return false;
    }
}
