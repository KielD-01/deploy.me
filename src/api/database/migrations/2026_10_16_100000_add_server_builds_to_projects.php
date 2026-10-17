<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // How the server builds images a compose file only names: one row
            // per service, {service, dockerfile, context, target, args}, where
            // args may use {sha} and {sha7}. Null when the compose file builds
            // its own images (or the project ships prebuilt ones).
            $table->jsonb('server_builds')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('server_builds');
        });
    }
};
