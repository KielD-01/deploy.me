<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Start the new containers beside the running ones, instead of
            // replacing them where they stand.
            $table->boolean('blue_green')->default(false);
        });

        Schema::table('releases', function (Blueprint $table) {
            // Which set of containers serves this release: blue or green.
            $table->string('color', 5)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('blue_green');
        });

        Schema::table('releases', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
