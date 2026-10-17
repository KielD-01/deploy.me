<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->timestamp('captured_at');

            $table->decimal('cpu_usage', 5, 1)->nullable();
            $table->unsignedInteger('cpu_mhz')->nullable();
            $table->decimal('load_1', 8, 2)->nullable();
            $table->decimal('load_5', 8, 2)->nullable();
            $table->decimal('load_15', 8, 2)->nullable();

            $table->unsignedBigInteger('mem_total')->nullable();
            $table->unsignedBigInteger('mem_available')->nullable();
            $table->unsignedBigInteger('swap_total')->nullable();
            $table->unsignedBigInteger('swap_free')->nullable();

            $table->json('disks')->nullable();
            $table->json('stacks')->nullable();
            $table->unsignedBigInteger('uptime')->nullable();
            $table->unsignedInteger('ssh_latency_ms')->nullable();

            $table->index(['server_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_snapshots');
    }
};
