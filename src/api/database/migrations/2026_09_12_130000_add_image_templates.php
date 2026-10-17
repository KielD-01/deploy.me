<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // How a commit's images are named, one row per compose variable
            // that pins one: {var, kind, image, tag}, where image and tag may
            // use {owner}, {repo}, {sha} and {sha7}. Null follows detection.
            $table->jsonb('image_templates')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('image_templates');
        });
    }
};
