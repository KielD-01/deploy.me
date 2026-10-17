<?php

namespace App\Platform\Ssh;

use App\Enums\AuditAction;
use App\Enums\AuditOutcome;
use App\Models\Server;
use App\Platform\Ssh\Exceptions\SshException;
use App\Support\Audit;

/**
 * Puts every command deploy.me runs on a server into the audit log: which
 * script, on which server, how it ended and how long it took. The script's
 * variables never go with it — they are the values the step logger redacts.
 *
 * One exception keeps the log readable: the scheduler's own looks at a server
 * (ping, probe) and at a target (the two health scripts) run about a thousand
 * times a day and nearly always end well, so inside `scheduled()` and without
 * a signed-in user, such a run is only recorded when it fails. A person's run
 * and a deploy's stay in the log whatever they are.
 */
final class AuditingSshClient implements SshClient
{
    /** The scripts the scheduler runs over and over. */
    private const ROUTINE = ['ping', 'probe', 'deploy-health', 'deploy-compose-health'];

    private static int $scheduled = 0;

    /** @var array<string, ?int> */
    private array $servers = [];

    public function __construct(private readonly SshClient $inner) {}

    /**
     * Runs the scheduler's work (a probe job, a health check job): the routine
     * scripts it runs that succeed are not audited.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function scheduled(callable $work): mixed
    {
        self::$scheduled++;

        try {
            return $work();
        } finally {
            self::$scheduled--;
        }
    }

    public function run(string $alias, RemoteScript $script, ?callable $onLine = null, int $timeout = 60, ?callable $onTick = null): SshResult
    {
        try {
            $result = $this->inner->run($alias, $script, $onLine, $timeout, $onTick);
        } catch (SshException $e) {
            $this->note($alias, $script, AuditOutcome::Failed, $e->userMessage());

            throw $e;
        }

        $this->note(
            $alias,
            $script,
            $result->successful() ? AuditOutcome::Ok : AuditOutcome::Failed,
            ($result->successful() ? '' : "exit {$result->exitCode} · ").round($result->seconds, 1).' s',
        );

        return $result;
    }

    private function note(string $alias, RemoteScript $script, AuditOutcome $outcome, string $detail): void
    {
        if ($outcome === AuditOutcome::Ok && self::$scheduled > 0 && ! auth()->hasUser() && in_array($script->name, self::ROUTINE, true)) {
            return;
        }

        Audit::record(AuditAction::RemoteCommand, [
            'subject' => "{$script->name} on {$alias}",
            'server_id' => $this->serverId($alias),
            'outcome' => $outcome,
            'detail' => $detail,
        ]);
    }

    private function serverId(string $alias): ?int
    {
        return $this->servers[$alias] ??= rescue(
            fn () => Server::query()->where('ssh_alias', $alias)->value('id'),
            null,
            report: false,
        );
    }
}
