<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            // How many migrations its deploy ran: rolling back past it keeps them.
            $table->unsignedSmallInteger('migrations')->default(0);
            // The database dump taken just before those migrations.
            $table->string('backup')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('releases', fn (Blueprint $table) => $table->dropColumn(['migrations', 'backup']));
    }
};
