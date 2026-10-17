<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            // The compose variables that pinned it to CI's images.
            $table->json('image_pins')->nullable();
        });

        Schema::table('projects', function (Blueprint $table) {
            // Deploy to every target when CI passes on the branch.
            $table->boolean('auto_deploy')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('auto_deploy'));
        Schema::table('releases', fn (Blueprint $table) => $table->dropColumn('image_pins'));
    }
};
