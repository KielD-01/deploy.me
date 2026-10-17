<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credentials', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->default('github');

            // The account the token belongs to, and every owner it reaches:
            // that account plus the organizations it is a member of.
            $table->string('login');
            $table->json('owners');

            // Encrypted with APP_KEY; only the hint is ever shown.
            $table->text('token');
            $table->string('token_hint', 20);
            $table->string('token_type', 20);
            $table->json('scopes')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamp('verified_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'login']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credentials');
    }
};
