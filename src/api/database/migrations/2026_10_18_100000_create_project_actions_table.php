<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Named jobs a project runs on a target when someone asks (or on a
        // schedule, or after a deploy): read from .deployme/actions/*.yml in
        // the repository (source "repo", read-only here) or made on the page
        // ("app"). `definition` is the checked, normalized form both share; a
        // repo file that doesn't pass keeps its `errors` and no definition.
        Schema::create('project_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('source', 10);
            // What the shell and MCP call it: the file's name, or one made from the name.
            $table->string('key', 64);
            $table->string('file')->nullable();
            $table->string('name');
            $table->json('definition')->nullable();
            $table->json('errors')->nullable();
            // The commit a repo action was read at.
            $table->string('commit_sha', 40)->nullable();
            $table->timestamp('loaded_at')->nullable();
            // The last schedule time the scheduler has dealt with (ran it, or let it go).
            $table->timestamp('scheduled_through')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'key']);
        });

        // A run of an action is a deployment of kind "action". No foreign key:
        // the run history outlives an action that's removed.
        Schema::table('deployments', function (Blueprint $table) {
            $table->unsignedBigInteger('action_id')->nullable();
            $table->index(['action_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropIndex(['action_id', 'id']);
            $table->dropColumn('action_id');
        });

        Schema::dropIfExists('project_actions');
    }
};
