<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who did what, to which object, when, and how it ended. Never the
        // values: no tokens, no env contents, nothing the step logger redacts.
        //
        // server_id, project_id and user_id carry no foreign keys on purpose.
        // The log outlives what it's about, and `on delete set null` would
        // have to UPDATE a row the trigger below refuses to let anyone change.
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at');
            $table->unsignedBigInteger('user_id')->nullable();
            // The name as it was: a user who leaves doesn't erase their trail.
            $table->string('actor', 100)->nullable();
            $table->string('action', 60);
            $table->string('subject', 200)->nullable();
            $table->unsignedBigInteger('server_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('outcome', 20);
            $table->string('detail', 250)->nullable();

            $table->index('occurred_at');
            $table->index(['server_id', 'occurred_at']);
            $table->index(['project_id', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
        });

        // Append-only: an UPDATE is always refused, and a DELETE only goes
        // through for the nightly prune, which says so in its own transaction
        // (set_config(..., true) can't outlive it).
        DB::unprepared(<<<'SQL'
            create or replace function deployme_audit_append_only() returns trigger as $$
            begin
                if tg_op = 'DELETE' and current_setting('deployme.pruning', true) = 'on' then
                    return old;
                end if;

                raise exception 'audit_log is append-only: % refused.', tg_op;
            end;
            $$ language plpgsql;

            create trigger audit_log_append_only
                before update or delete on audit_log
                for each row execute function deployme_audit_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop trigger if exists audit_log_append_only on audit_log');
        Schema::dropIfExists('audit_log');
        DB::unprepared('drop function if exists deployme_audit_append_only()');
    }
};
