<?php
// api/database/migrations/2026_10_03_000100_add_hub_tool_to_oauth_clients.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Which tool an OAuth client is. A client-credentials token acts as that tool (reaching only orgs
 * that linked it, and only competitor sets visible to it). NULL for clients that are not a tool.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE oauth_clients ADD COLUMN hub_tool text NULL CHECK (hub_tool IN ('web'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE oauth_clients DROP COLUMN hub_tool');
    }
};
