<?php
// api/database/migrations/2026_10_04_000000_create_geo_areas_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Geography hierarchy (02-data-model.md §2.1): market -> region -> state -> country. Markets are ABS
 * suburbs/localities, regions ABS Greater Capital City areas. Reference data, not tenant data: no
 * org_id and no RLS, and app_user may only read it (the default privileges grant would otherwise
 * let it write). The ABS data load belongs to the benchmarking plan.
 *
 * Also adds the venues.market_id foreign key Plan A left for this plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE geo_areas (
                id uuid PRIMARY KEY,
                parent_id uuid NULL REFERENCES geo_areas(id),
                level text NOT NULL CHECK (level IN ('market', 'region', 'state', 'country')),
                code text NOT NULL,
                name text NOT NULL,
                UNIQUE (level, code)
            )
        SQL);
        DB::statement('REVOKE INSERT, UPDATE ON geo_areas FROM app_user');

        DB::statement('ALTER TABLE venues ADD CONSTRAINT venues_market_id_fk FOREIGN KEY (market_id) REFERENCES geo_areas(id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE venues DROP CONSTRAINT venues_market_id_fk');
        DB::statement('DROP TABLE geo_areas');
    }
};
