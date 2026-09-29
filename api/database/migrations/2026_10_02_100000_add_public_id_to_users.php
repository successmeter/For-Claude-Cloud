<?php
// api/database/migrations/2026_10_02_100000_add_public_id_to_users.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The only user id that leaves the Hub (id_token `sub`, userinfo, /hub/v1/me). The bigint users.id
 * is sequential and stays internal. Existing rows are backfilled by the column default.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users ADD COLUMN public_id uuid NOT NULL DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_public_id_unique UNIQUE (public_id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP COLUMN public_id');
    }
};
