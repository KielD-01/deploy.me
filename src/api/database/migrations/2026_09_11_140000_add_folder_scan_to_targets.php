<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('targets', function (Blueprint $table) {
            // What the folder holds besides deploy.me's own: the last read, for
            // converting an earlier in-place deployment to releases.
            $table->json('folder_scan')->nullable();
            $table->timestamp('folder_scanned_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('targets', fn (Blueprint $table) => $table->dropColumn(['folder_scan', 'folder_scanned_at']));
    }
};
