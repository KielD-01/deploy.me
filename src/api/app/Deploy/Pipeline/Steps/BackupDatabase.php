<?php

namespace App\Deploy\Pipeline\Steps;

use App\Deploy\Compose;
use App\Deploy\DeployContext;
use App\Deploy\Pipeline\Step;
use App\Deploy\Pipeline\StepFailed;
use App\Deploy\Pipeline\StepTimeout;
use App\Models\Project;
use App\Models\Release;
use App\Support\DotEnv;
use App\Support\Format;

/**
 * Before migrations run: a pg_dump of the project's database into
 * .deploy/backups on the server (the newest few stay), when the deploy asks
 * for one and the env files say where the database is.
 *
 * pg_dump turns row security off for the dump, and a role that policies apply
 * to is then refused: the project says which role to dump as, either an env
 * key holding it (DB_USERNAME by default) or a role name written out. A role
 * written out connects over the container's own socket, which is how the
 * container's superuser gets in without a password.
 */
final class BackupDatabase implements Step
{
    public const KEY = 'backup';

    public const KEEP = 5;

    // A database name the dump's file can be named after.
    public const NAME = '/^[A-Za-z0-9_][A-Za-z0-9_-]*$/';

    // deploy-db-backup.sh: pg_dump was refused by row-level security.
    private const ROW_SECURITY = 41;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Back up database';
    }

    public function shouldRun(DeployContext $context): bool
    {
        $options = $context->deployment->options ?? [];

        return RunMigrations::applies($context)
            && ($options['backup'] ?? false)
            && self::database($context->project) !== null;
    }

    public function run(DeployContext $context): ?string
    {
        /** @var array{container: string, database: string, username: string, password: string, source: string} $database */
        $database = self::database($context->project);
        $keep = self::keep($context->project);

        // The dump is named after the database, and the dumps it keeps are
        // found by that name: nothing in it may reach another folder or match
        // another project's files.
        if (preg_match(self::NAME, $database['database']) !== 1) {
            throw new StepFailed("DB_DATABASE in the project’s env file, “{$database['database']}”, isn’t a database name deploy.me backs up: letters, digits, _ and - only, not starting with -. Nothing was migrated.", 2);
        }

        $context->log->note("Dumping {$database['database']} in the {$database['container']} container as {$database['username']} ({$database['source']}).");

        $run = $context->script('deploy-db-backup', [
            'CONTAINER' => $database['container'],
            'NETWORKS' => implode("\n", Compose::externalNetworks($context->project)),
            'DATABASE' => $database['database'],
            'DB_USER' => $database['username'],
            'DB_PASSWORD' => $database['password'],
            'DIR' => $context->layout->deployDir().'/backups',
            'KEEP' => $keep,
        ], timeout: StepTimeout::LONG);

        if ($run->result->exitCode === self::ROW_SECURITY) {
            throw new StepFailed(
                "pg_dump can’t read {$database['database']} as {$database['username']}: row-level security policies apply to that role, and pg_dump reads with row security off. "
                ."Set “Back up as” on the project’s Settings tab to a role the policies don’t apply to — the table owner, a role with BYPASSRLS, or the {$database['container']} container’s superuser (write `postgres`, which connects over the container’s own socket) — or deploy with the backup switched off.",
                self::ROW_SECURITY,
            );
        }

        $run->throwIfFailed('The database backup failed, so nothing was migrated.');

        $file = (string) $run->marker('file');
        $release = $context->memo['release'] ?? null;
        if ($release instanceof Release) {
            $release->update(['backup' => $file]);
        }

        return $file.' · '.Format::bytes((int) $run->marker('size')).' · '.$keep.' kept';
    }

    /**
     * Where the project's Postgres database is, from its env files: the
     * container is DB_HOST (the shared stack's postgres, as a rule), and the
     * role is whatever the project's backup setting resolves to.
     *
     * @return array{container: string, database: string, username: string, password: string, source: string}|null
     */
    public static function database(Project $project): ?array
    {
        if (! $project->dockerized) {
            return null;
        }

        foreach ($project->envFiles()->get() as $file) {
            $env = DotEnv::parse($file->content);

            if (($env['DB_CONNECTION'] ?? null) !== 'pgsql' || (string) ($env['DB_DATABASE'] ?? '') === '') {
                continue;
            }

            $host = (string) ($env['DB_HOST'] ?? '');

            return [
                'container' => preg_match('/^[A-Za-z][A-Za-z0-9_.-]*$/', $host) === 1 && $host !== 'localhost' ? $host : 'postgres',
                'database' => (string) $env['DB_DATABASE'],
                ...self::role($project, $env),
            ];
        }

        return null;
    }

    /**
     * The role pg_dump connects as. An UPPER_CASE setting names an env key,
     * and the password beside it (DB_USERNAME → DB_PASSWORD) comes along;
     * anything else is a role name, which connects without a password over
     * the container's own socket.
     *
     * @param  array<string, string>  $env
     * @return array{username: string, password: string, source: string}
     */
    public static function role(Project $project, array $env): array
    {
        $setting = trim((string) $project->backup_role);
        $key = $setting !== '' ? $setting : 'DB_USERNAME';

        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
            return ['username' => $key, 'password' => '', 'source' => 'the role itself'];
        }

        $username = (string) ($env[$key] ?? '');

        if ($username === '') {
            return ['username' => 'postgres', 'password' => '', 'source' => "no {$key} in the env file"];
        }

        return [
            'username' => $username,
            'password' => (string) ($env[preg_replace('/(_USERNAME|_USER|_ROLE)$/', '', $key).'_PASSWORD'] ?? ''),
            'source' => "from {$key}",
        ];
    }

    public static function keep(Project $project): int
    {
        return max(1, (int) ($project->backup_keep ?: self::KEEP));
    }

    public static function available(Project $project): bool
    {
        return RunMigrations::availableFor($project) && self::database($project) !== null;
    }
}
