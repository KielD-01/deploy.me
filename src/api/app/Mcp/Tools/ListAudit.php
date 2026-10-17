<?php

namespace App\Mcp\Tools;

use App\Mcp\Describe;
use App\Mcp\Tool;
use App\Models\AuditEntry;

class ListAudit extends Tool
{
    public function name(): string
    {
        return 'list_audit';
    }

    public function description(): string
    {
        return 'The audit log, newest first: who trusted a host key, broke a lock, changed a token or removed something, and (with commands=true) every command deploy.me ran on a server. It never holds a value.';
    }

    public function properties(): array
    {
        return [
            'commands' => ['type' => 'boolean', 'default' => false, 'description' => 'Include the commands deploys and probes ran on servers.'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 30],
        ];
    }

    public function call(array $arguments): array
    {
        $entries = AuditEntry::query()
            ->when(! $this->bool($arguments, 'commands', false), fn ($query) => $query->byHand())
            ->latest('occurred_at')
            ->latest('id')
            ->limit($this->int($arguments, 'limit', 30, max: 200))
            ->get();

        return [
            'entries' => $entries->map(fn (AuditEntry $entry) => [
                'at' => Describe::time($entry->occurred_at),
                'actor' => $entry->actor,
                'action' => $entry->action->label(),
                'subject' => $entry->subject,
                'outcome' => $entry->outcome->value,
                'detail' => $entry->detail,
            ])->all(),
        ];
    }
}
