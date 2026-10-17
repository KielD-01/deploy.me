<?php

namespace App\Platform\Ssh\Testing;

use App\Platform\Ssh\RemoteScript;
use App\Platform\Ssh\SshClient;
use App\Platform\Ssh\SshResult;
use Closure;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Throwable;

/**
 * An SshClient for tests: canned responses per "alias:script" (either side may
 * be "*"), and a record of every call. Unexpected calls fail loudly.
 */
final class FakeSshClient implements SshClient
{
    /** @var array<string, SshResult|Throwable|Closure> */
    private array $responses = [];

    /** @var list<array{alias: string, script: RemoteScript, timeout: int}> */
    public array $calls = [];

    public function on(string $alias, string $script, SshResult|Throwable|Closure $response): self
    {
        $this->responses["{$alias}:{$script}"] = $response;

        return $this;
    }

    public function run(string $alias, RemoteScript $script, ?callable $onLine = null, int $timeout = 60, ?callable $onTick = null): SshResult
    {
        $this->calls[] = ['alias' => $alias, 'script' => $script, 'timeout' => $timeout];

        $response = $this->responses["{$alias}:{$script->name}"]
            ?? $this->responses["*:{$script->name}"]
            ?? $this->responses["{$alias}:*"]
            ?? $this->responses['*:*']
            ?? throw new RuntimeException("Unexpected ssh call: {$alias} {$script->name}");

        if ($response instanceof Closure) {
            $response = $response($alias, $script);
        }

        if ($response instanceof Throwable) {
            throw $response;
        }

        if ($onLine !== null) {
            foreach (preg_split('/\R/', rtrim($response->stdout)) as $line) {
                $onLine($line, 'out');
            }
        }

        if ($onTick !== null) {
            $onTick();
        }

        return $response;
    }

    public static function ok(string $stdout = ''): SshResult
    {
        return new SshResult(0, $stdout, '', 0.02);
    }

    public static function fail(string $stderr, int $exitCode = 255): SshResult
    {
        return new SshResult($exitCode, '', $stderr, 0.02);
    }

    public function assertRan(string $alias, string $script): void
    {
        Assert::assertTrue(
            collect($this->calls)->contains(fn ($call) => $call['alias'] === $alias && $call['script']->name === $script),
            "Expected an ssh call {$alias} {$script}.",
        );
    }

    public function assertNothingRan(): void
    {
        Assert::assertSame([], $this->calls, 'Expected no ssh calls.');
    }
}
