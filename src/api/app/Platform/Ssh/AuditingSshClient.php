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
 */
final class AuditingSshClient implements SshClient
{
    /** @var array<string, ?int> */
    private array $servers = [];

    public function __construct(private readonly SshClient $inner) {}

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
