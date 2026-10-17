<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The audit page's "By hand" listing (AuditEntry::byHand) is the newest
        // 50 of the few rows that aren't `ssh.command`, which is nearly all of
        // the table. Without this the planner walks the table backwards, one
        // command row at a time, to find them.
        DB::statement("create index audit_log_by_hand_index on audit_log (occurred_at desc, id desc) where action <> 'ssh.command'");
    }

    public function down(): void
    {
        DB::statement('drop index if exists audit_log_by_hand_index');
    }
};
