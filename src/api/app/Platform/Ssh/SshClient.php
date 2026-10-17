<?php

namespace App\Platform\Ssh;

use App\Platform\Ssh\Exceptions\SshException;

interface SshClient
{
    /**
     * Runs a script on the server behind an ssh alias. The script travels over
     * stdin; nothing user-supplied is ever on the ssh command line.
     *
     * @param  (callable(string $line, string $stream): void)|null  $onLine  Called per output line ('out' or 'err').
     * @param  (callable(): void)|null  $onTick  Called every ~200 ms while the script runs, output or not;
     *                                           throwing from it stops the script.
     *
     * @throws SshException only for timeouts; call ->throw() on the result for the rest.
     */
    public function run(string $alias, RemoteScript $script, ?callable $onLine = null, int $timeout = 60, ?callable $onTick = null): SshResult;
}
