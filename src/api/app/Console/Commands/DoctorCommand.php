<?php

namespace App\Console\Commands;

use App\Actions\Servers\CheckRoute;
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

    protected $description = 'Check the mode (dev or prod), the flavour, the database (PostgreSQL or SQLite), Valkey, Reverb, queues, the scheduler, host mounts, every server alias and the GitHub tokens';

    private int $failures = 0;

    public function handle(): int
    {
        $this->mode();

        $this->database();
        $this->check('Migrations are up to date', fn () => $this->pendingMigrations() === [] ?: 'pending: '.implode(', ', $this->pendingMigrations()));
        if ($this->usesRedis()) {
            $this->check('Valkey answers', fn () => (bool) Redis::connection()->ping());
        }
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
            $beat = Cache::get('deployme:scheduler:heartbeat');

            if ($beat === null) {
                // Right after the worker starts, the first heartbeat is under a minute away.
                return 'no heartbeat yet: it beats once a minute, so check again in a minute (make workers says whether it runs)';
            }

            $age = now()->getTimestamp() - (int) $beat;

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
     * Production mode runs the image's code, so it should run it the way
     * production does: debug off, every cache built, OPcache trusting the files.
     * In development mode debug on is the point, and the caches are cleared at
     * start on purpose.
     */
    private function mode(): void
    {
        if (config('deployme.mode') !== 'prod') {
            $this->line('  <fg=gray>Development mode: the code is read live from src/api</>');

            return;
        }

        $this->line('  <fg=gray>Production mode: the code is the image’s (make build && make up after a change)</>');

        $this->check('APP_DEBUG is off', fn () => ! config('app.debug')
            ?: 'APP_DEBUG=true in src/api/.env shows a stack trace to anyone who hits an error: run bin/configure --mode prod, then make restart');

        $this->check('APP_ENV is production', fn () => app()->isProduction()
            ?: 'APP_ENV is '.app()->environment().': run bin/configure --mode prod, then make restart');

        $this->check('Config, routes and events are cached', function () {
            $missing = array_keys(array_filter([
                'config' => app()->configurationIsCached(),
                'routes' => app()->routesAreCached(),
                'events' => app()->eventsAreCached(),
            ], fn (bool $cached) => ! $cached));

            return $missing === [] ?: 'not cached: '.implode(', ', $missing).' (each container caches them when it starts: see its log, then make restart)';
        });

        $this->check('Views are compiled', function () {
            $folder = (string) config('view.compiled');

            return glob($folder.'/*.php') !== [] ?: "none in {$folder} (deployme-web compiles them when it starts: see its log)";
        });

        $this->check('OPcache doesn’t re-check file timestamps', function () {
            $value = ini_get('opcache.validate_timestamps');

            return ($value !== false && ! filter_var($value, FILTER_VALIDATE_BOOLEAN))
                ?: 'opcache.validate_timestamps is on: the image’s php-prod.ini is missing (make build && make up)';
        });
    }

    /**
     * The database the flavour runs on. SQLite (slim) is one file that every
     * container writes to: it should be there, in WAL mode, with a busy timeout.
     */
    private function database(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->check('Postgres answers', fn () => DB::select('select 1 as ok')[0]->ok === 1);

            return;
        }

        $this->line('  <fg=gray>Slim flavour: SQLite at '.DB::connection()->getDatabaseName().', queues and cache in it</>');
        $this->check('SQLite answers', fn () => (int) DB::selectOne('select 1 as ok')->ok === 1);
        $this->check('SQLite writes ahead (WAL), so readers don’t wait for a writer', function () {
            $mode = strtolower((string) DB::selectOne('pragma journal_mode')->journal_mode);

            return $mode === 'wal' ?: "journal_mode is {$mode} (DB_JOURNAL_MODE in src/api/.env)";
        });
        $this->check('SQLite waits for a busy database instead of failing', function () {
            $timeout = (int) DB::selectOne('pragma busy_timeout')->timeout;

            return $timeout >= 1000 ?: "busy_timeout is {$timeout} ms (DB_BUSY_TIMEOUT in src/api/.env)";
        });
    }

    /** Whether the cache or one of deploy.me's queues is on Valkey (the full flavour). */
    private function usesRedis(): bool
    {
        $drivers = [config('cache.stores.'.config('cache.default').'.driver')];

        foreach (['redis', 'redis-deploys', 'redis-probes'] as $connection) {
            $drivers[] = config("queue.connections.{$connection}.driver");
        }

        return in_array('redis', $drivers, true);
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

            if ($server->hasRoute()) {
                $route = $server->connectionRoute();

                if ($route->kind->isProxy()) {
                    $this->check("{$alias}: the proxy helper can run", fn () => is_executable((string) config('deployme.ssh.php_binary')) ?: config('deployme.ssh.php_binary').' is missing (DEPLOYME_PHP_BINARY)');
                }

                $this->check("{$alias}: the route ({$route->label()}) is up", function () use ($server) {
                    $check = app(CheckRoute::class)($server, 'doctor');

                    return $check->state->ok() ?: $check->detail;
                });
            }
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
