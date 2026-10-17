<?php

namespace App\Mcp;

/**
 * One tool an AI agent can call through `deployme:mcp`. A tool reads the
 * database, or (deploys()) starts or stops work on a server, which the
 * server only offers with --allow-deploys. Results are plain arrays the
 * server hands back as JSON; never a secret, never an env file's contents.
 */
abstract class Tool
{
    abstract public function name(): string;

    abstract public function description(): string;

    /**
     * JSON Schema properties of the arguments.
     *
     * @return array<string, array<string, mixed>>
     */
    public function properties(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public function required(): array
    {
        return [];
    }

    /**
     * Starts or stops a run on a server: offered only with --allow-deploys.
     */
    public function deploys(): bool
    {
        return false;
    }

    /**
     * Changes nothing anywhere (a probe only reads the server, so it counts).
     */
    public function readOnly(): bool
    {
        return ! $this->deploys();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws ToolFailed
     */
    abstract public function call(array $arguments): array;

    /**
     * What tools/list says about this tool.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => $this->description(),
            'inputSchema' => [
                'type' => 'object',
                'properties' => (object) $this->properties(),
                'required' => $this->required(),
                'additionalProperties' => false,
            ],
            'annotations' => [
                'readOnlyHint' => $this->readOnly(),
                'destructiveHint' => $this->deploys(),
                'idempotentHint' => $this->readOnly(),
                'openWorldHint' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function string(array $arguments, string $key, ?string $default = null): ?string
    {
        $value = $arguments[$key] ?? null;

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return $default;
        }

        if (! is_string($value) && ! is_int($value)) {
            throw new ToolFailed("“{$key}” must be a string.");
        }

        return trim((string) $value);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function requiredString(array $arguments, string $key): string
    {
        return $this->string($arguments, $key) ?? throw new ToolFailed("“{$key}” is required.");
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function int(array $arguments, string $key, int $default, int $min = 1, int $max = PHP_INT_MAX): int
    {
        $value = $arguments[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new ToolFailed("“{$key}” must be a whole number.");
        }

        return max($min, min($max, (int) $value));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function bool(array $arguments, string $key, bool $default): bool
    {
        $value = $arguments[$key] ?? null;

        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? throw new ToolFailed("“{$key}” must be true or false.");
    }
}
