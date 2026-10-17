<?php

namespace App\Platform\Git;

use Illuminate\Contracts\Process\ProcessResult;
use RuntimeException;

final class GitFailed extends RuntimeException
{
    public function __construct(string $message, public readonly int $exitCode = 1)
    {
        parent::__construct($message);
    }

    /**
     * @param  list<string>  $args
     */
    public static function from(ProcessResult $result, array $args): self
    {
        $output = trim($result->errorOutput()) ?: trim($result->output());
        $lines = array_slice(array_values(array_filter(array_map('trim', explode("\n", $output)))), -3);
        $message = GitRemote::redact(implode(' ', $lines));

        return new self($message !== '' ? $message : 'git '.($args[0] ?? '').' failed.', $result->exitCode() ?? 1);
    }
}
