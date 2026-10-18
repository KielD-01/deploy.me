<?php

namespace App\Deploy\Actions;

use App\Deploy\DeploymentRefused;
use App\Deploy\Logging\SecretRedactor;

/**
 * An action's inputs: what someone gave (a form's strings, the shell's k=v,
 * an agent's JSON) checked and typed against the definition, and how they
 * reach the steps: as UPPER_CASE environment variables sent over stdin.
 */
final class ActionInputs
{
    /**
     * The value of every input: the one given, else its default. Unknown
     * names, a missing value with no default, a number that isn't one, an
     * option that isn't offered, are refused, all at once.
     *
     * @param  array<string, mixed>  $given
     * @return array<string, string|int|bool>
     *
     * @throws DeploymentRefused
     */
    public static function resolve(ActionDefinition $definition, array $given): array
    {
        $values = [];
        $problems = [];
        $known = array_column($definition->inputs(), 'name');

        foreach (array_keys($given) as $name) {
            if (! in_array((string) $name, $known, true)) {
                $problems[] = "{$definition->name()} has no input “{$name}”".($known !== [] ? ' (it takes '.implode(', ', $known).')' : '').'.';
            }
        }

        foreach ($definition->inputs() as $input) {
            $name = $input['name'];
            $value = $given[$name] ?? null;

            if ($value === null || $value === '') {
                if ($input['default'] === null) {
                    $problems[] = "{$name}: give a value.";

                    continue;
                }
                $value = $input['default'];
            }

            $typed = self::cast($input['type'], $value, $input['options']);

            if ($typed === null) {
                $problems[] = match ($input['type']) {
                    'number' => "{$name}: a whole number.",
                    'boolean' => "{$name}: yes or no.",
                    'choice' => "{$name}: one of ".implode(', ', $input['options']).'.',
                    default => "{$name}: a line of text, up to 1000 characters.",
                };

                continue;
            }

            $values[$name] = $typed;
        }

        if ($problems !== []) {
            throw new DeploymentRefused(implode(' ', $problems));
        }

        return $values;
    }

    /**
     * @param  list<string>  $options
     */
    private static function cast(string $type, mixed $value, array $options): string|int|bool|null
    {
        return match ($type) {
            'number' => is_int($value) ? $value : (is_string($value) && preg_match('/^\s*-?\d{1,15}\s*$/', $value) === 1 ? (int) trim($value) : null),
            'boolean' => is_bool($value) ? $value : match (is_scalar($value) ? strtolower(trim((string) $value)) : '') {
                '1', 'y', 'yes', 'true', 'on' => true,
                '0', 'n', 'no', 'false', 'off' => false,
                default => null,
            },
            'choice' => is_scalar($value) && in_array((string) $value, $options, true) ? (string) $value : null,
            default => is_scalar($value) && ! is_bool($value) && mb_strlen((string) $value) <= 1000 ? (string) $value : null,
        };
    }

    /**
     * The variables the steps get: INDEX=all, FRESH=1 (0 when off).
     *
     * @param  array<string, string|int|bool>  $values
     * @return array<string, string>
     */
    public static function env(ActionDefinition $definition, array $values): array
    {
        $env = [];

        foreach ($definition->inputs() as $input) {
            if (array_key_exists($input['name'], $values)) {
                $value = $values[$input['name']];
                $env[ActionDefinition::envName($input['name'])] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            }
        }

        return $env;
    }

    /**
     * The inputs as the run page, the shell and the audit-free lists show
     * them: index=all fresh=yes, a secret as its mask.
     *
     * @param  array<string, string|int|bool>  $values
     * @return array<string, string>
     */
    public static function display(ActionDefinition $definition, array $values): array
    {
        $shown = [];

        foreach ($definition->inputs() as $input) {
            if (array_key_exists($input['name'], $values)) {
                $value = $values[$input['name']];
                $shown[$input['name']] = match (true) {
                    $input['type'] === 'secret' => SecretRedactor::MASK,
                    is_bool($value) => $value ? 'yes' : 'no',
                    default => (string) $value,
                };
            }
        }

        return $shown;
    }

    /**
     * The secret inputs' values, kept apart from the rest: encrypted on the
     * run until it starts, then gone.
     *
     * @param  array<string, string|int|bool>  $values
     * @return array<string, string>
     */
    public static function secrets(ActionDefinition $definition, array $values): array
    {
        $secrets = [];

        foreach ($definition->inputs() as $input) {
            if ($input['type'] === 'secret' && array_key_exists($input['name'], $values)) {
                $secrets[$input['name']] = (string) $values[$input['name']];
            }
        }

        return $secrets;
    }

    /**
     * "index=all fresh=yes", or an em dash for none.
     *
     * @param  array<string, string>  $shown
     */
    public static function line(array $shown): string
    {
        return $shown === [] ? '—' : implode(' ', array_map(fn (string $name, string $value) => "{$name}={$value}", array_keys($shown), $shown));
    }
}
