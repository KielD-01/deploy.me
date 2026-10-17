<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How deploy.me reaches the server: direct, a jump host, a SOCKS5 or
        // HTTP proxy, or a VPN on this machine. `route` holds the host, port,
        // user and check address; the proxy password is encrypted on its own.
        // The last check's result stays on the server, the history in route_checks.
        Schema::table('servers', function (Blueprint $table) {
            $table->string('route_kind', 20)->default('direct');
            $table->json('route')->nullable();
            $table->text('route_secret')->nullable();
            $table->string('route_state', 30)->nullable();
            $table->string('route_detail', 300)->nullable();
            $table->timestamp('route_checked_at')->nullable();
        });

        Schema::create('route_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('state', 30);
            // What asked: a probe, a deploy, a person on the server page, the doctor.
            $table->string('reason', 20);
            $table->foreignId('deployment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('detail', 300)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('checked_at');

            $table->index(['server_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_checks');

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['route_kind', 'route', 'route_secret', 'route_state', 'route_detail', 'route_checked_at']);
        });
    }
};
