<?php

namespace App\Support;

use App\Enums\AuditAction;
use App\Enums\AuditOutcome;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Writes the audit log: who did what, to which object, when, and how it
 * ended. Never a value — no tokens, no env contents, nothing the step logger
 * would have redacted — and never loud enough to break what it records: a
 * write that fails is reported and forgotten.
 */
final class Audit
{
    /**
     * @param  array{subject?: ?string, detail?: ?string, outcome?: AuditOutcome, server_id?: ?int, project_id?: ?int, user?: ?User}  $about
     */
    public static function record(AuditAction $action, array $about = []): ?AuditEntry
    {
        return rescue(function () use ($action, $about) {
            // A queued run carries its own user; work nobody started has none.
            $user = $about['user'] ?? (auth()->hasUser() ? auth()->user() : null);

            return AuditEntry::query()->create([
                'occurred_at' => now(),
                'user_id' => $user?->id,
                // Unattended work — the scheduler, a queued job — has no user.
                'actor' => $user?->name ?? $user?->email,
                'action' => $action,
                'subject' => self::trim($about['subject'] ?? null, 200),
                'server_id' => $about['server_id'] ?? null,
                'project_id' => $about['project_id'] ?? null,
                'outcome' => $about['outcome'] ?? AuditOutcome::Ok,
                'detail' => self::trim($about['detail'] ?? null, 250),
            ]);
        }, null);
    }

    private static function trim(?string $value, int $length): ?string
    {
        return $value !== null && trim($value) !== '' ? Str::limit(trim($value), $length - 1) : null;
    }
}
