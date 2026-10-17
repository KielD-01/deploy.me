<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A retry picks up where a finished deploy stopped: the run it retries
        // (its release, commit and strategy), and the step it starts from.
        Schema::table('deployments', function (Blueprint $table) {
            $table->foreignId('retry_of_id')->nullable()->constrained('deployments')->nullOnDelete();
            $table->string('resume_from', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('retry_of_id');
            $table->dropColumn('resume_from');
        });
    }
};
