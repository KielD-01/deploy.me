<?php

namespace App\Platform\Ssh;

use App\Platform\Ssh\Exceptions\SshConfigError;
use Illuminate\Support\Facades\Process;

class SshTargetResolver
{
    public function __construct(private readonly SshConfigCompiler $compiler) {}

    public function resolve(string $alias): SshTarget
    {
        SshAlias::assertValid($alias);

        $result = Process::timeout(10)->run([
            config('deployme.ssh.binary'), '-F', $this->compiler->compile(), '-G', $alias,
        ]);

        if ($result->failed()) {
            throw new SshConfigError(trim($result->errorOutput()) ?: "ssh -G {$alias} failed.", $result->errorOutput(), (int) $result->exitCode());
        }

        $values = [];
        foreach (preg_split('/\R/', trim($result->output())) as $line) {
            [$key, $value] = array_pad(explode(' ', $line, 2), 2, '');
            $values[strtolower($key)][] = $value;
        }

        $first = fn (string $key, ?string $default = null) => $values[$key][0] ?? $default;
        $noneToNull = fn (?string $value) => ($value === null || $value === 'none') ? null : $value;

        return new SshTarget(
            alias: $alias,
            hostName: $first('hostname', $alias),
            user: $first('user', ''),
            port: (int) $first('port', '22'),
            identityFiles: $values['identityfile'] ?? [],
            proxyJump: $noneToNull($first('proxyjump')),
            hostKeyAlias: $noneToNull($first('hostkeyalias')),
            identitiesOnly: $first('identitiesonly') === 'yes',
        );
    }
}
