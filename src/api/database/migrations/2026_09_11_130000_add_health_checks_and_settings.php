<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('targets', function (Blueprint $table) {
            $table->timestamp('health_checked_at')->nullable();
            // What the last check found, in a few words.
            $table->string('health_detail')->nullable();
            // Since when it has been failing: "502 for 4 min".
            $table->timestamp('unhealthy_since')->nullable();
        });

        // App-wide settings that aren't environment: schedules, retention.
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::table('targets', fn (Blueprint $table) => $table->dropColumn(['health_checked_at', 'health_detail', 'unhealthy_since']));
    }
};
