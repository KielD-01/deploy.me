<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();

            // Where the connection comes from: an alias in ~/.ssh/config, and
            // what `ssh -G` resolved it to when the server was added.
            $table->string('ssh_alias');
            $table->string('host_name')->nullable();
            $table->string('ssh_user')->nullable();
            $table->unsignedSmallInteger('port')->default(22);
            $table->json('identity_files')->nullable();
            $table->string('proxy_jump')->nullable();

            $table->string('env_tag', 20)->default('development');
            $table->string('status', 20)->default('online');
            $table->string('status_reason')->nullable();

            $table->string('default_deploy_path')->nullable();
            $table->json('reserved_paths')->nullable();

            // Static facts and capabilities from the latest probe.
            $table->json('specs')->nullable();
            $table->json('capabilities')->nullable();

            $table->unsignedInteger('probe_interval')->default(300);
            $table->timestamp('last_probe_at')->nullable();
            $table->timestamp('next_probe_at')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        // One live server per alias; archived rows keep their history.
        DB::statement('CREATE UNIQUE INDEX servers_ssh_alias_active_unique ON servers (ssh_alias) WHERE archived_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
