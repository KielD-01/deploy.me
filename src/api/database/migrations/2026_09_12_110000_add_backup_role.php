<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Who pg_dump connects as: an env key whose value is the role
            // (DB_USERNAME by default), or a role name written out. A role
            // row-level security applies to can't dump the database at all.
            $table->string('backup_role', 100)->nullable();
            // How many dumps stay in .deploy/backups.
            $table->unsignedSmallInteger('backup_keep')->default(5);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['backup_role', 'backup_keep']);
        });
    }
};
