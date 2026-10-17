<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the RefreshDrift job last measured for the dashboard's "Deployed
        // vs latest": the branch head, how many commits the live release is
        // behind it, the uncommitted files in a local repository, and the
        // commit the live release was at when it measured (so a deploy since
        // is noticed).
        Schema::table('targets', function (Blueprint $table) {
            $table->string('drift_head', 40)->nullable();
            $table->string('drift_deployed', 40)->nullable();
            $table->unsignedInteger('drift_behind')->nullable();
            $table->unsignedInteger('drift_dirty')->nullable();
            $table->timestamp('drift_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('targets', function (Blueprint $table) {
            $table->dropColumn(['drift_head', 'drift_deployed', 'drift_behind', 'drift_dirty', 'drift_checked_at']);
        });
    }
};
