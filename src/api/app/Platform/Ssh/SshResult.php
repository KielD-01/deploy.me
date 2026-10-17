<?php

namespace App\Platform\Ssh;

use App\Platform\Ssh\Exceptions\SshException;

final readonly class SshResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public float $seconds,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * @throws SshException
     */
    public function throw(): self
    {
        if (! $this->successful()) {
            throw SshErrors::from($this);
        }

        return $this;
    }

    /**
     * The text between two sentinel lines, so noisy shell profiles can't corrupt parsed output.
     */
    public function between(string $begin, string $end): ?string
    {
        $start = strpos($this->stdout, $begin);
        $stop = $start === false ? false : strpos($this->stdout, $end, $start);

        return $start === false || $stop === false
            ? null
            : trim(substr($this->stdout, $start + strlen($begin), $stop - $start - strlen($begin)));
    }
}
