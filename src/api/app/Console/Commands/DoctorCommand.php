<?php

namespace App\Console\Commands;

use App\Models\Credential;
use App\Models\Server;
use App\Models\SshHostKey;
use App\Platform\Ssh\Exceptions\SshException;
use App\Platform\Ssh\SshTarget;
use App\Platform\Ssh\SshTargetResolver;
use App\Support\QueueTimeouts;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Throwable;

class DoctorCommand extends Command
{
    protected $signature = 'deployme:doctor';

    protected $description = 'Check Postgres, Valkey, Reverb, queues, the scheduler, host mounts, every server alias and the GitHub tokens';

    private int $failures = 0;

    public function handle(): int
    {
        $this->check('Postgres answers', fn () => DB::select('select 1 as ok')[0]->ok === 1);
        $this->check('Migrations are up to date', fn () => $this->pendingMigrations() === [] ?: 'pending: '.implode(', ', $this->pendingMigrations()));
        $this->check('Valkey answers', fn () => (bool) Redis::connection()->ping());
        $this->check('Reverb credentials are set', fn () => filled(config('broadcasting.connections.reverb.key')) && filled(config('broadcasting.connections.reverb.secret')));
        $this->check('Reverb accepts a broadcast', function () {
            Broadcast::connection('reverb')->broadcast(['deployme-doctor'], 'ping', ['at' => now()->toIso8601String()]);

            return true;
        });

        $queues = config('deployme.supervisor_queues_conf');
        if (is_readable($queues)) {
            $this->check('Queue retry_after is above every worker timeout', fn () => ($v = QueueTimeouts::violations($queues)) === [] ?: implode('; ', $v));
        }

        $this->check('The scheduler ran in the last 3 minutes', function () {
            $age = now()->getTimestamp() - (int) Cache::get('deployme:scheduler:heartbeat', 0);

            return $age < 180 ?: "last heartbeat {$age}s ago";
        });

        $this->check('~/.ssh/config is readable', fn () => is_readable(config('deployme.ssh.source_config')));

        foreach (config('deployme.project_roots') as $root) {
            $this->check("{$root} is mounted", fn () => is_dir($root));
        }

        $this->servers();
        $this->tokens();

        if (getenv('DEPLOYME_ROLE') === 'worker') {
            $this->check('All supervisor programs are running', function () {
                $status = Process::run(['supervisorctl', '-c', '/etc/supervisor/supervisord.conf', 'status']);

                return $status->successful() ?: trim($status->output());
            });
        }

        return $this->failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Every server alias: that ssh resolves it, that the keys it would offer
     * are private enough for ssh to use, and that its host key is trusted.
     */
    private function servers(): void
    {
        $servers = Server::query()->whereNull('archived_at')->orderBy('ssh_alias')->get();

        if ($servers->isEmpty()) {
            return;
        }

        $resolver = app(SshTargetResolver::class);

        foreach ($servers as $server) {
            $alias = $server->ssh_alias;

            try {
                $target = $resolver->resolve($alias);
            } catch (SshException $e) {
                $this->check("ssh -G {$alias} resolves", fn () => $e->userMessage());

                continue;
            }

            $this->check("ssh -G {$alias} resolves", fn () => true);
            $this->line("    <fg=gray>{$target->user}@{$target->hostName}:{$target->port}</>");

            $this->check("{$alias}: the keys ssh would offer are private", fn () => self::keyPermissions($target), fatal: false);
            $this->check("{$alias}: its host key is trusted", fn () => self::hostKeyKnown($alias, $target));
        }
    }

    /**
     * A key ssh refuses to use is the kind of thing that only shows up as
     * "permission denied" at deploy time.
     */
    private static function keyPermissions(SshTarget $target): bool|string
    {
        $bad = [];
        $found = 0;

        foreach ($target->identityFiles as $file) {
            $path = str_starts_with($file, '~/') ? rtrim((string) ($_SERVER['HOME'] ?? '/root'), '/').substr($file, 1) : $file;

            if (! is_file($path)) {
                continue;
            }

            $found++;
            $mode = fileperms($path) & 0777;

            if (($mode & 0077) !== 0) {
                $bad[] = $file.' is '.substr(sprintf('%o', $mode), -3).', ssh wants 600';
            }
        }

        return match (true) {
            $bad !== [] => implode('; ', $bad),
            $found === 0 => 'no key file of its own is there; ssh falls back to the agent',
            default => true,
        };
    }

    private static function hostKeyKnown(string $alias, SshTarget $target): bool|string
    {
        if (SshHostKey::query()->where('alias', $alias)->whereNull('revoked_at')->exists()) {
            return true;
        }

        $file = (string) config('deployme.ssh.user_known_hosts');
        $host = $target->hostKeyAlias ?? ($target->port === 22 ? $target->hostName : "[{$target->hostName}]:{$target->port}");

        if (is_readable($file) && Process::timeout(10)->run([config('deployme.ssh.keygen_binary'), '-F', $host, '-f', $file])->successful()) {
            return true;
        }

        return "nothing trusted for {$host}; add the server again to trust it";
    }

    /**
     * The GitHub tokens: still valid, and able to do what deploy.me asks of them.
     */
    private function tokens(): void
    {
        foreach (Credential::query()->where('provider', 'github')->orderBy('login')->get() as $credential) {
            $this->check("GitHub token for {$credential->login} hasn’t expired", function () use ($credential) {
                if ($credential->expires_at === null) {
                    return true;
                }

                return $credential->expires_at->isFuture()
                    ? true
                    : 'expired '.$credential->expires_at->diffForHumans();
            });

            $this->check("GitHub token for {$credential->login} expires no time soon", function () use ($credential) {
                return $credential->expires_at === null || $credential->expires_at->isPast() || $credential->expires_at->gt(now()->addDays(14))
                    ? true
                    : 'expires '.$credential->expires_at->diffForHumans();
            }, fatal: false);

            $this->check("GitHub token for {$credential->login} can read repositories and packages", function () use ($credential) {
                if ($credential->token_type !== 'classic') {
                    return $credential->scopes === null || $credential->scopes === []
                        ? 'fine-grained: check its repository and package permissions on GitHub'
                        : true;
                }

                $missing = array_values(array_filter(['repo', 'read:packages'], fn (string $scope) => ! $credential->hasScope($scope)));

                return $missing === [] ? true : 'missing '.implode(', ', $missing);
            }, fatal: false);
        }
    }

    /**
     * Runs one check. The closure returns true, or false / a string explaining
     * the failure. A check that isn't fatal says so in yellow and doesn't make
     * the command fail.
     */
    private function check(string $label, Closure $probe, bool $fatal = true): void
    {
        try {
            $result = $probe();
        } catch (Throwable $e) {
            $result = $e->getMessage();
        }

        if ($result === true) {
            $this->line("  <fg=green>✓</> {$label}");

            return;
        }

        $detail = is_string($result) ? " <fg=gray>— {$result}</>" : '';

        if (! $fatal) {
            $this->line("  <fg=yellow>!</> {$label}{$detail}");

            return;
        }

        $this->failures++;
        $this->line("  <fg=red>✗</> {$label}{$detail}");
    }

    /**
     * @return list<string>
     */
    private function pendingMigrations(): array
    {
        $migrator = app('migrator');

        if (! $migrator->repositoryExists()) {
            return ['migrations table missing'];
        }

        $files = array_keys($migrator->getMigrationFiles([database_path('migrations')]));

        return array_values(array_diff($files, $migrator->getRepository()->getRan()));
    }
}
