<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // The project's own last word on whether the release is healthy:
            // a command run on the server, in the release's folder, after the
            // Docker and HTTP checks. A non-zero exit fails the deploy.
            $table->string('health_command', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('health_command');
        });
    }
};
