<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ssh_host_keys', function (Blueprint $table) {
            $table->id();
            $table->string('alias');
            $table->string('host_pattern');
            $table->string('key_type', 64);
            $table->text('known_hosts_line');
            $table->string('fingerprint')->nullable();
            $table->foreignId('trusted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('trusted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['host_pattern', 'key_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssh_host_keys');
    }
};
