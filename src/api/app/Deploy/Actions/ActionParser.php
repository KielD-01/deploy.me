<?php

namespace App\Deploy\Actions;

use App\Enums\HookRunIn;
use Cron\CronExpression;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads an action from its YAML (.deployme/actions/<key>.yml) or from the
 * same shape as an array (the editor), strictly: an unknown key, a step that
 * says neither what nor where, a cron that isn't one, are errors, all of them
 * listed, and nothing is half-taken.
 *
 *   name: Reindex search
 *   on: { manual: true, schedule: "30 4 * * 0", target: edge-fra, after_deploy: succeeded }
 *   inputs:
 *     index: { type: choice, options: [products, orders], default: products }
 *   steps:
 *     - { name: Import, one-off: worker, run: php artisan scout:import "$INDEX", timeout: 15m }
 *   targets: [edge-fra]
 *   confirm: passkey
 */
final class ActionParser
{
    public const MAX_STEPS = 30;

    public const MAX_INPUTS = 20;

    private const TOP = ['name', 'description', 'on', 'inputs', 'steps', 'targets', 'confirm', 'mcp', 'alert'];

    private const ON = ['manual', 'schedule', 'target', 'after_deploy'];

    private const INPUT = ['type', 'options', 'default', 'description'];

    private const STEP = ['name', 'run', 'exec', 'one-off', 'if', 'timeout', 'continue-on-error', 'env-file'];

    private const TYPES = ['text' => 'text', 'string' => 'text', 'number' => 'number', 'integer' => 'number', 'boolean' => 'boolean', 'bool' => 'boolean', 'yes/no' => 'boolean', 'choice' => 'choice', 'secret' => 'secret'];

    /** @var list<string> */
    private array $errors = [];

    /**
     * @return array{0: ?ActionDefinition, 1: list<string>}
     */
    public static function yaml(string $yaml): array
    {
        try {
            // No custom tags, objects or PHP constants: a file in the repo is data.
            $raw = Yaml::parse($yaml);
        } catch (ParseException $e) {
            return [null, ['YAML: '.$e->getMessage()]];
        }

        if (! is_array($raw) || array_is_list($raw)) {
            return [null, ['The file should be a mapping: name, on, inputs, steps…']];
        }

        return self::array($raw);
    }

    /**
     * @param  array<mixed>  $raw
     * @return array{0: ?ActionDefinition, 1: list<string>}
     */
    public static function array(array $raw): array
    {
        $parser = new self;
        $data = $parser->normalize($raw);

        return $parser->errors === [] ? [new ActionDefinition($data), []] : [null, $parser->errors];
    }

    /**
     * @param  array<mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalize(array $raw): array
    {
        $this->unknown($raw, self::TOP, '');

        $name = $raw['name'] ?? null;
        if (! is_string($name) || trim($name) === '' || mb_strlen($name) > 100) {
            $this->error('name: give the action a name (up to 100 characters).');
        }

        $description = $raw['description'] ?? null;
        if ($description !== null && (! is_string($description) || mb_strlen($description) > 300)) {
            $this->error('description: a line of text, up to 300 characters.');
            $description = null;
        }

        $on = $this->on($raw['on'] ?? ['manual' => true]);
        $inputs = $this->inputs($raw['inputs'] ?? []);
        $steps = $this->steps($raw['steps'] ?? null, $inputs);
        $targets = $this->targets($raw['targets'] ?? null);

        $confirm = $raw['confirm'] ?? null;
        if ($confirm !== null && $confirm !== 'passkey') {
            $this->error('confirm: the only value is passkey.');
        }
        $passkey = $confirm === 'passkey';

        $mcp = $this->bool($raw, 'mcp', false, 'mcp');
        $alert = $this->bool($raw, 'alert', true, 'alert');

        // A passkey is a person at the keyboard: nothing that runs by itself can give one.
        if ($passkey) {
            foreach (['schedule' => 'on.schedule', 'after_deploy' => 'on.after_deploy'] as $key => $where) {
                if ($on[$key] !== null) {
                    $this->error("{$where}: an action that asks for a passkey runs only when someone starts it, so it can’t run on its own.");
                }
            }
            if ($mcp) {
                $this->error('mcp: an action that asks for a passkey can’t be started by an agent.');
            }
        }

        if (! $on['manual'] && $on['schedule'] === null && $on['after_deploy'] === null) {
            $this->error('on: nothing would ever start it. Set manual: true, a schedule or after_deploy.');
        }

        return [
            'name' => is_string($name) ? trim($name) : '',
            'description' => is_string($description) && trim($description) !== '' ? trim($description) : null,
            ...$on,
            'inputs' => $inputs,
            'steps' => $steps,
            'targets' => $targets,
            'passkey' => $passkey,
            'mcp' => $mcp,
            'alert' => $alert,
        ];
    }

    /**
     * @return array{manual: bool, schedule: ?string, schedule_target: ?string, after_deploy: ?string}
     */
    private function on(mixed $on): array
    {
        $result = ['manual' => true, 'schedule' => null, 'schedule_target' => null, 'after_deploy' => null];

        // on: manual (or a list of trigger names) is short for the mapping.
        if (is_string($on)) {
            $on = [$on => true];
        } elseif (is_array($on) && array_is_list($on)) {
            $on = array_fill_keys(array_filter($on, 'is_string'), true);
        }

        if (! is_array($on)) {
            $this->error('on: a mapping of manual, schedule (+ target) and after_deploy.');

            return $result;
        }

        $this->unknown($on, self::ON, 'on.');
        $result['manual'] = $this->bool($on, 'manual', ! isset($on['schedule']) && ! isset($on['after_deploy']), 'on.manual');

        $schedule = $on['schedule'] ?? null;
        $target = $on['target'] ?? null;

        // schedule: { cron: "0 3 * * *", target: edge-fra } says the same.
        if (is_array($schedule)) {
            $this->unknown($schedule, ['cron', 'target'], 'on.schedule.');
            $target ??= $schedule['target'] ?? null;
            $schedule = $schedule['cron'] ?? null;
        }

        if ($schedule !== null) {
            $cron = is_string($schedule) ? trim(preg_replace('/\s+/', ' ', $schedule) ?? '') : '';

            if (count(explode(' ', $cron)) !== 5 || ! CronExpression::isValidExpression($cron)) {
                $this->error('on.schedule: “'.(is_scalar($schedule) ? $schedule : '…').'” isn’t a cron expression of five fields (minute hour day month weekday), like "0 3 * * *".');
            } else {
                $result['schedule'] = $cron;
            }
        }

        if ($target !== null) {
            if (! is_string($target) || trim($target) === '') {
                $this->error('on.target: the server the schedule runs on, by name.');
            } elseif ($schedule === null) {
                $this->error('on.target: only a schedule has a target of its own.');
            } else {
                $result['schedule_target'] = trim($target);
            }
        }

        $after = $on['after_deploy'] ?? null;
        if ($after === true) {
            $after = 'succeeded';
        }
        if ($after !== null && $after !== false) {
            if (! in_array($after, ActionDefinition::AFTER_DEPLOY, true)) {
                $this->error('on.after_deploy: succeeded, failed or either.');
            } else {
                $result['after_deploy'] = $after;
            }
        }

        return $result;
    }

    /**
     * @return list<array{name: string, type: string, options: list<string>, default: string|int|bool|null, description: ?string}>
     */
    private function inputs(mixed $inputs): array
    {
        if ($inputs === null || $inputs === []) {
            return [];
        }

        if (! is_array($inputs) || array_is_list($inputs)) {
            $this->error('inputs: a mapping of names to { type, options, default, description }.');

            return [];
        }

        if (count($inputs) > self::MAX_INPUTS) {
            $this->error('inputs: at most '.self::MAX_INPUTS.'.');
        }

        $result = [];
        $env = [];

        foreach ($inputs as $name => $spec) {
            $name = (string) $name;
            $at = "inputs.{$name}";

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,39}$/', $name) !== 1) {
                $this->error("{$at}: a name of letters, digits, - and _ (it becomes an environment variable).");

                continue;
            }

            $variable = ActionDefinition::envName($name);
            if (isset($env[$variable]) || str_starts_with($variable, 'DEPLOYME_')) {
                $this->error("{$at}: {$variable} is ".(isset($env[$variable]) ? 'another input’s variable already.' : 'deploy.me’s own.'));

                continue;
            }
            $env[$variable] = true;

            // `fresh: boolean` is short for { type: boolean }.
            if (is_string($spec)) {
                $spec = ['type' => $spec];
            }
            $spec ??= [];

            if (! is_array($spec) || array_is_list($spec) && $spec !== []) {
                $this->error("{$at}: { type, options, default, description }.");

                continue;
            }

            $this->unknown($spec, self::INPUT, "{$at}.");
            $type = self::TYPES[strtolower((string) ($spec['type'] ?? 'text'))] ?? null;

            if ($type === null) {
                $this->error("{$at}.type: one of text, number, boolean, choice, secret.");

                continue;
            }

            $options = [];
            if ($type === 'choice') {
                $raw = $spec['options'] ?? null;
                if (! is_array($raw) || ! array_is_list($raw) || $raw === [] || count(array_filter($raw, 'is_scalar')) !== count($raw)) {
                    $this->error("{$at}.options: a choice needs a list of options.");
                } else {
                    $options = array_values(array_unique(array_map(fn ($option) => is_bool($option) ? ($option ? 'true' : 'false') : (string) $option, $raw)));
                }
            } elseif (isset($spec['options'])) {
                $this->error("{$at}.options: only a choice has options.");
            }

            $default = $spec['default'] ?? null;
            if ($default !== null) {
                $default = match ($type) {
                    'boolean' => is_bool($default) ? $default : $this->invalid("{$at}.default: true or false."),
                    'number' => is_int($default) || (is_string($default) && preg_match('/^-?\d+$/', $default) === 1) ? (int) $default : $this->invalid("{$at}.default: a whole number."),
                    'choice' => in_array((string) $default, $options, true) ? (string) $default : ($options === [] ? null : $this->invalid("{$at}.default: one of the options.")),
                    'secret' => $this->invalid("{$at}.default: a secret has no default; it would be in the repository."),
                    default => is_scalar($default) ? (string) $default : $this->invalid("{$at}.default: a line of text."),
                };
            } elseif ($type === 'boolean') {
                $default = false;
            }

            $description = $spec['description'] ?? null;
            if ($description !== null && ! is_string($description)) {
                $this->error("{$at}.description: a line of text.");
                $description = null;
            }

            $result[] = ['name' => $name, 'type' => $type, 'options' => $options, 'default' => $default, 'description' => $description !== null ? trim($description) : null];
        }

        return $result;
    }

    /**
     * @param  list<array{name: string, type: string}>  $inputs
     * @return list<array{name: string, run: string, run_in: string, service: ?string, if: ?string, timeout: int, continue_on_error: bool, env_file: bool}>
     */
    private function steps(mixed $steps, array $inputs): array
    {
        if (! is_array($steps) || ! array_is_list($steps) || $steps === []) {
            $this->error('steps: a list of at least one step, each with run (and exec or one-off to run in a service).');

            return [];
        }

        if (count($steps) > self::MAX_STEPS) {
            $this->error('steps: at most '.self::MAX_STEPS.'.');
        }

        $booleans = array_column(array_filter($inputs, fn (array $input) => $input['type'] === 'boolean'), 'name');
        $result = [];

        foreach ($steps as $index => $step) {
            $number = $index + 1;
            $at = "steps.{$number}";

            // - php artisan optimize:clear  (a step that's only a command)
            if (is_string($step)) {
                $step = ['run' => $step];
            }

            if (! is_array($step) || array_is_list($step)) {
                $this->error("{$at}: { name, run, exec or one-off, if, timeout, continue-on-error }.");

                continue;
            }

            $this->unknown($step, self::STEP, "{$at}.");

            $run = $step['run'] ?? null;
            if (! is_string($run) || trim($run) === '' || strlen($run) > 4000) {
                $this->error("{$at}.run: the command to run (up to 4000 characters).");
                $run = '';
            }

            $name = $step['name'] ?? null;
            if ($name !== null && (! is_string($name) || mb_strlen($name) > 100)) {
                $this->error("{$at}.name: a line of text, up to 100 characters.");
                $name = null;
            }

            $runIn = HookRunIn::Host;
            $service = null;

            if (isset($step['exec'], $step['one-off'])) {
                $this->error("{$at}: exec or one-off, not both.");
            } elseif (isset($step['exec']) || isset($step['one-off'])) {
                $runIn = isset($step['exec']) ? HookRunIn::ServiceExec : HookRunIn::ServiceRun;
                $service = $step['exec'] ?? $step['one-off'];
                $key = isset($step['exec']) ? 'exec' : 'one-off';

                if (! is_string($service) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$/', $service) !== 1) {
                    $this->error("{$at}.{$key}: the Compose service to run it in.");
                    $service = null;
                }
            }

            $if = $step['if'] ?? null;
            if ($if !== null) {
                $input = is_string($if) && preg_match('/^\s*inputs\.([A-Za-z0-9_-]+)\s*$/', $if, $match) === 1 ? $match[1] : null;

                if ($input === null || ! in_array($input, $booleans, true)) {
                    $this->error("{$at}.if: inputs.<name> of a boolean input".($booleans !== [] ? ' ('.implode(', ', $booleans).')' : '').'.');
                }
                $if = $input;
            }

            $timeout = $this->timeout($step['timeout'] ?? null, "{$at}.timeout");

            $result[] = [
                'name' => is_string($name) && trim($name) !== '' ? trim($name) : ($run !== '' ? mb_strimwidth(trim(strtok($run, "\n") ?: $run), 0, 60, '…') : "Step {$number}"),
                'run' => rtrim($run),
                'run_in' => $runIn->value,
                'service' => $service,
                'if' => $if,
                'timeout' => $timeout,
                'continue_on_error' => $this->bool($step, 'continue-on-error', false, "{$at}.continue-on-error"),
                'env_file' => $this->bool($step, 'env-file', false, "{$at}.env-file"),
            ];
        }

        return $result;
    }

    /**
     * 600, "90s", "15m", "1h" → seconds, between 10 s and an hour.
     */
    private function timeout(mixed $value, string $at): int
    {
        if ($value === null) {
            return ActionDefinition::DEFAULT_TIMEOUT;
        }

        $seconds = match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/^\s*(\d+)\s*(s|m|min|h)?\s*$/i', $value, $match) === 1 => (int) $match[1] * match (strtolower($match[2] ?? 's')) {
                'm', 'min' => 60,
                'h' => 3600,
                default => 1,
            },
            default => null,
        };

        if ($seconds === null || $seconds < 10 || $seconds > 3600) {
            $this->error("{$at}: between 10s and 1h, like 600, 90s or 15m.");

            return ActionDefinition::DEFAULT_TIMEOUT;
        }

        return $seconds;
    }

    /**
     * @return list<string>|null
     */
    private function targets(mixed $targets): ?array
    {
        if ($targets === null) {
            return null;
        }

        if (is_string($targets)) {
            $targets = [$targets];
        }

        if (! is_array($targets) || ! array_is_list($targets) || $targets === [] || count(array_filter($targets, fn ($name) => is_string($name) && trim($name) !== '')) !== count($targets)) {
            $this->error('targets: a list of server names; leave it out for every server the project is on.');

            return null;
        }

        return array_values(array_unique(array_map('trim', $targets)));
    }

    /**
     * @param  array<mixed>  $from
     */
    private function bool(array $from, string $key, bool $default, string $at): bool
    {
        $value = $from[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        if (! is_bool($value)) {
            $this->error("{$at}: true or false.");

            return $default;
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $map
     * @param  list<string>  $known
     */
    private function unknown(array $map, array $known, string $prefix): void
    {
        foreach (array_keys($map) as $key) {
            if (! in_array($key, $known, true)) {
                $this->error("{$prefix}{$key}: not a key deploy.me knows. It takes ".implode(', ', $known).'.');
            }
        }
    }

    private function invalid(string $message): null
    {
        $this->error($message);

        return null;
    }

    private function error(string $message): void
    {
        $this->errors[] = $message;
    }
}
