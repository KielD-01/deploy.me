<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_snapshots', function (Blueprint $table) {
            // The probe's busiest processes: ten by CPU, by memory and by network.
            $table->json('processes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('server_snapshots', function (Blueprint $table) {
            $table->dropColumn('processes');
        });
    }
};
