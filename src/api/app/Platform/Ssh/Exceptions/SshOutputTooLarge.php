<?php

namespace App\Platform\Ssh\Exceptions;

/**
 * A command printed more than `deployme.ssh.max_output_bytes`, so ssh was
 * stopped. It is an SshTimeout on purpose: a caller that lets go of a script on
 * a timeout (a deploy stopping what it started on the server) does the same
 * here, since the script is still printing.
 */
class SshOutputTooLarge extends SshTimeout
{
    /** How much of the end of each stream is kept. */
    public const TAIL_BYTES = 65536;

    /**
     * @param  string  $stdout  the last TAIL_BYTES of what the command printed
     * @param  string  $stderr  the last TAIL_BYTES of what it printed on stderr
     */
    public function __construct(public readonly int $limit, public readonly string $stdout = '', string $stderr = '')
    {
        $size = $limit >= 1048576 && $limit % 1048576 === 0 ? ($limit / 1048576).' MB' : number_format($limit).' bytes';

        parent::__construct("The command printed more than {$size}, so it was stopped.", $stderr);
    }

    public function userMessage(): string
    {
        return $this->getMessage();
    }
}
