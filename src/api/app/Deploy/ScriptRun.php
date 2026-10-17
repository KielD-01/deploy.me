<?php

namespace App\Deploy;

use App\Deploy\Pipeline\StepFailed;
use App\Platform\Ssh\Exceptions\SshException;
use App\Platform\Ssh\SshErrors;
use App\Platform\Ssh\SshResult;
use Illuminate\Support\Str;

/**
 * A deploy script's result, with the ::deployme::key=value markers it printed.
 */
final readonly class ScriptRun
{
    /**
     * @param  array<string, string>  $markers
     */
    public function __construct(
        public SshResult $result,
        public array $markers,
    ) {}

    public function ok(): bool
    {
        return $this->result->successful();
    }

    public function marker(string $key): ?string
    {
        return $this->markers[$key] ?? null;
    }

    /**
     * @throws StepFailed when the script failed; SshException when ssh did
     */
    public function throwIfFailed(string $fallback): self
    {
        if ($this->ok()) {
            return $this;
        }

        // 255: ssh itself failed (connection, host key, key refused).
        if ($this->result->exitCode === 255) {
            throw SshErrors::from($this->result);
        }

        throw new StepFailed($this->failure($fallback), $this->result->exitCode);
    }

    /**
     * The script's own explanation: its last "✗ …" line, else stderr's last line.
     */
    public function failure(string $fallback): string
    {
        foreach (array_reverse(preg_split('/\R/', $this->result->stdout) ?: []) as $line) {
            if (str_starts_with($line, '✗ ')) {
                return substr($line, strlen('✗ '));
            }
        }

        $stderr = array_values(array_filter(preg_split('/\R/', trim($this->result->stderr)) ?: []));

        return $stderr !== [] ? Str::limit((string) end($stderr), 300) : $fallback;
    }
}
