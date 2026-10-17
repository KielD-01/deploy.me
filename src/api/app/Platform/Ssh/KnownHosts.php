<?php

namespace App\Platform\Ssh;

use App\Enums\AuditAction;
use App\Models\SshHostKey;
use App\Support\Audit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Trust on first use. Host keys trusted in deploy.me live in the database and
 * are written into each container's memory-only known_hosts; the user's own
 * ~/.ssh/known_hosts is consulted read-only next to it.
 */
class KnownHosts
{
    private const REVISION_KEY = 'deployme:known-hosts:revision';

    public function __construct(private readonly SshConfigCompiler $compiler) {}

    /**
     * Connects just far enough to receive the host key, with every
     * authentication method switched off, and records what was offered.
     */
    public function scan(string $alias): HostKeyScan
    {
        SshAlias::assertValid($alias);

        $config = $this->compiler->compile();
        $file = (string) tempnam(sys_get_temp_dir(), 'deployme-hostkey');

        try {
            $result = Process::timeout(20)->run([
                config('deployme.ssh.binary'), '-F', $config,
                '-o', 'StrictHostKeyChecking=accept-new',
                '-o', "UserKnownHostsFile={$file}",
                '-o', 'GlobalKnownHostsFile=/dev/null',
                '-o', 'ControlMaster=no',
                '-o', 'ControlPath=none',
                '-o', 'PubkeyAuthentication=no',
                '-o', 'PasswordAuthentication=no',
                '-o', 'KbdInteractiveAuthentication=no',
                '-o', 'GSSAPIAuthentication=no',
                '-o', 'HostbasedAuthentication=no',
                '-T', $alias, 'true',
            ]);

            $lines = array_values(array_filter(array_map('trim', file($file) ?: [])));

            if ($lines === []) {
                throw SshErrors::from(new SshResult(255, '', $result->errorOutput() ?: 'The server offered no host key.', 0));
            }

            $keygen = Process::timeout(10)->run([config('deployme.ssh.keygen_binary'), '-l', '-E', 'sha256', '-f', $file]);

            $keys = [];
            foreach (preg_split('/\R/', trim($keygen->output())) as $line) {
                // "256 SHA256:abc… 203.0.113.10 (ED25519)"
                if (preg_match('/^\d+\s+(SHA256:\S+)\s.*\((\S+)\)$/', trim($line), $m)) {
                    $keys[] = ['type' => $m[2], 'fingerprint' => $m[1]];
                }
            }

            return new HostKeyScan($alias, $lines, $keys);
        } finally {
            @unlink($file);
        }
    }

    public function trust(HostKeyScan $scan, ?int $userId): void
    {
        foreach ($scan->lines as $index => $line) {
            [$pattern, $type] = preg_split('/\s+/', $line, 3);

            SshHostKey::query()->updateOrCreate(
                ['host_pattern' => $pattern, 'key_type' => $type, 'revoked_at' => null],
                [
                    'alias' => $scan->alias,
                    'known_hosts_line' => $line,
                    'fingerprint' => $scan->keys[$index]['fingerprint'] ?? null,
                    'trusted_by' => $userId,
                    'trusted_at' => now(),
                ],
            );
        }

        Cache::forever(self::REVISION_KEY, (string) Str::uuid());
        $this->materialize();

        Audit::record(AuditAction::HostKeyTrusted, [
            'subject' => $scan->alias,
            'detail' => implode(', ', array_map(fn (array $key) => "{$key['type']} {$key['fingerprint']}", $scan->keys)),
        ]);
    }

    /**
     * Writes the trusted keys into this container's known_hosts when they changed.
     */
    public function materialize(): void
    {
        $this->compiler->compile();

        $revision = (string) Cache::get(self::REVISION_KEY, 'initial');
        $path = $this->compiler->knownHostsPath();
        $marker = $path.'.revision';

        if (is_file($path) && @file_get_contents($marker) === $revision) {
            return;
        }

        $lines = SshHostKey::query()->whereNull('revoked_at')->orderBy('id')->pluck('known_hosts_line')->all();

        $tmp = $path.'.'.getmypid().'.tmp';
        file_put_contents($tmp, $lines === [] ? '' : implode("\n", $lines)."\n");
        chmod($tmp, 0600);
        rename($tmp, $path);
        file_put_contents($marker, $revision);
    }
}
