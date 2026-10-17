<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One run of the deploy engine against a target: a deploy, a rollback,
        // or preparing the folders (bootstrap). The id is the run number (#128).
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);
            $table->string('status', 20)->default('queued');
            $table->string('trigger', 20)->default('manual');
            $table->string('commit_sha', 40)->nullable();
            $table->string('commit_message')->nullable();
            $table->string('strategy', 20)->nullable();
            $table->json('strategy_trace')->nullable();
            $table->json('options')->nullable();
            // What happened, in a sentence, for the run page and the list.
            $table->text('outcome')->nullable();
            $table->unsignedInteger('log_lines')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'heartbeat_at']);
        });

        // One run at a time per target; the remote lease is the second guard.
        DB::statement("CREATE UNIQUE INDEX deployments_one_active_per_target ON deployments (target_id) WHERE status IN ('queued', 'waiting_ci', 'running')");

        Schema::create('deployment_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->string('label');
            $table->unsignedSmallInteger('position');
            $table->string('status', 20)->default('pending');
            $table->string('detail')->nullable();
            $table->integer('exit_code')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('started_at', 3)->nullable();
            $table->timestamp('finished_at', 3)->nullable();

            $table->unique(['deployment_id', 'key']);
        });

        // Append-only; written in batches by the StepLogger, read with the
        // query builder.
        Schema::create('deployment_logs', function (Blueprint $table) {
            $table->foreignId('deployment_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('seq');
            $table->string('step_key', 40)->nullable();
            $table->string('stream', 8);
            $table->text('line');
            $table->timestamp('logged_at', 3);

            $table->primary(['deployment_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_logs');
        Schema::dropIfExists('deployment_steps');
        Schema::dropIfExists('deployments');
    }
};
