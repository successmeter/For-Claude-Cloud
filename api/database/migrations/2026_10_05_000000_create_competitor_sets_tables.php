<?php
// api/database/migrations/2026_10_05_000000_create_competitor_sets_tables.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Competitor sets owned by the Hub and shared with other tools (design §4.4, decisions B4/B5).
 * A member is a business, tool-neutral; each tool keeps its own data handle (the Web tool's GA4
 * property id) in its own database.
 *
 * Nothing is ever deleted: sets soft-delete, members get removed_at. So there is no ON DELETE
 * CASCADE and no DELETE grant (default privileges give app_user SELECT/INSERT/UPDATE only).
 * The composite FK (set_id, org_id) stops a member row from hanging off another org's set.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE competitor_sets (
                id uuid PRIMARY KEY,
                org_id uuid NOT NULL REFERENCES orgs(id),
                name text NOT NULL CHECK (length(name) BETWEEN 1 AND 120),
                tools text[] NOT NULL CHECK (cardinality(tools) >= 1 AND tools <@ ARRAY['revenue', 'web']::text[]),
                version integer NOT NULL DEFAULT 1,
                activated_at timestamptz NULL,
                composition_locked_until timestamptz NULL,
                created_by bigint NULL REFERENCES users(id),
                created_at timestamptz NULL,
                updated_at timestamptz NULL,
                deleted_at timestamptz NULL,
                CONSTRAINT competitor_sets_id_org_unique UNIQUE (id, org_id)
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE competitor_set_members (
                id uuid PRIMARY KEY,
                org_id uuid NOT NULL REFERENCES orgs(id),
                set_id uuid NOT NULL,
                name text NOT NULL CHECK (length(name) BETWEEN 1 AND 200),
                website_url text NULL,
                location_text text NULL,
                cuisine text NULL,
                market_id uuid NULL REFERENCES geo_areas(id),
                removed_at timestamptz NULL,
                created_at timestamptz NULL,
                updated_at timestamptz NULL,
                CONSTRAINT members_set_same_org FOREIGN KEY (set_id, org_id) REFERENCES competitor_sets (id, org_id)
            )
        SQL);
        DB::statement('CREATE INDEX competitor_set_members_set_idx ON competitor_set_members (set_id) WHERE removed_at IS NULL');

        foreach (['competitor_sets', 'competitor_set_members'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY {$table}_tenant_isolation ON {$table}
                USING (org_id::text = current_setting('app.current_org_id', true))
                WITH CHECK (org_id::text = current_setting('app.current_org_id', true))");
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE competitor_set_members');
        DB::statement('DROP TABLE competitor_sets');
    }
};
