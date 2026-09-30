<?php
// api/database/migrations/2026_10_07_000200_create_ingest_orgs_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Org ids that have uploads, so the scheduled clean-up (ingest:expire-runs, ingest:prune-snapshots)
 * can find them: app_user cannot list orgs without tenant context. Ids only, no RLS (like
 * webhook_outbox); the commands then work inside each org's tenant context.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE TABLE ingest_orgs (org_id uuid PRIMARY KEY REFERENCES orgs(id) ON DELETE CASCADE, created_at timestamptz NOT NULL DEFAULT now())');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE ingest_orgs');
    }
};
