<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Org ids with a POS connection, so the nightly pos:sync can find them: app_user cannot list orgs
 * without tenant context. Ids only, no RLS (like ingest_orgs); the sync then works inside each
 * org's tenant context.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE TABLE pos_orgs (org_id uuid PRIMARY KEY REFERENCES orgs(id) ON DELETE CASCADE, created_at timestamptz NOT NULL DEFAULT now())');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE pos_orgs');
    }
};
