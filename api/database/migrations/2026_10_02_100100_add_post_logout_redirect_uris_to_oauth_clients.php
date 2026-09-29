<?php
// api/database/migrations/2026_10_02_100100_add_post_logout_redirect_uris_to_oauth_clients.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where /oauth/logout may send a browser back to, per client (exact match only, so logout can never
 * become an open redirect).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->json('post_logout_redirect_uris')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->dropColumn('post_logout_redirect_uris');
        });
    }
};
