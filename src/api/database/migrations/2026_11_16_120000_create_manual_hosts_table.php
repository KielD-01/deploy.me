<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Servers typed in by hand (Add server → Enter manually) instead of
        // picked from ~/.ssh/config: what `ssh -i key -p port user@host` says,
        // under an alias SshConfigCompiler writes a Host block for. Only the
        // key's path is kept (~/.ssh/<file>), never the key.
        Schema::create('manual_hosts', function (Blueprint $table) {
            $table->id();
            $table->string('alias', 100)->unique();
            $table->string('host_name', 253);
            $table->string('user', 64);
            $table->unsignedInteger('port')->default(22);
            $table->string('identity_file', 255)->nullable();
            $table->string('proxy_jump', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_hosts');
    }
};
