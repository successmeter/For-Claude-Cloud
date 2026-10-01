<?php
// api/database/migrations/2026_10_10_000000_plan_e_sales_split_and_covers.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan E (design §2): sales split into food / drinks / other, covers per day, Square categories per
 * day and the owner's category mapping. Amounts are net sales incl. GST in cents (decision E4).
 */
return new class extends Migration
{
    private const TABLES = ['daily_covers', 'daily_covers_revisions', 'sales_daily_categories', 'category_mappings'];

    public function up(): void
    {
        // The split is all or nothing, and adds up to the day's revenue.
        foreach (['sales_daily' => '', 'sales_daily_revisions' => 'new_'] as $table => $prefix) {
            DB::statement("ALTER TABLE {$table}
                ADD COLUMN {$prefix}food_cents bigint NULL CHECK ({$prefix}food_cents >= 0),
                ADD COLUMN {$prefix}drinks_cents bigint NULL CHECK ({$prefix}drinks_cents >= 0),
                ADD COLUMN {$prefix}other_cents bigint NULL CHECK ({$prefix}other_cents >= 0)");
        }
        DB::statement('ALTER TABLE sales_daily_revisions
            ADD COLUMN old_food_cents bigint NULL, ADD COLUMN old_drinks_cents bigint NULL, ADD COLUMN old_other_cents bigint NULL');
        DB::statement('ALTER TABLE sales_daily ADD CONSTRAINT sales_daily_split_complete CHECK (
            (food_cents IS NULL AND drinks_cents IS NULL AND other_cents IS NULL)
            OR (food_cents IS NOT NULL AND drinks_cents IS NOT NULL AND other_cents IS NOT NULL
                AND food_cents + drinks_cents + other_cents = revenue_cents))');

        DB::statement(<<<'SQL'
            CREATE TABLE daily_covers (
                org_id uuid NOT NULL,
                venue_id uuid NOT NULL,
                business_date date NOT NULL,
                covers integer NOT NULL CHECK (covers >= 0 AND covers <= 100000),
                source text NOT NULL CHECK (source IN ('manual', 'upload', 'booking')),
                updated_by bigint NULL REFERENCES users(id),
                updated_at timestamptz NOT NULL,
                PRIMARY KEY (venue_id, business_date),
                CONSTRAINT daily_covers_venue_same_org FOREIGN KEY (venue_id, org_id)
                    REFERENCES venues (id, org_id) ON DELETE CASCADE
            )
        SQL);
        DB::statement(<<<'SQL'
            CREATE TABLE daily_covers_revisions (
                id bigserial PRIMARY KEY,
                org_id uuid NOT NULL,
                venue_id uuid NOT NULL,
                business_date date NOT NULL,
                old_covers integer NULL,
                old_source text NULL,
                new_covers integer NULL,
                new_source text NULL,
                changed_by bigint NULL REFERENCES users(id),
                changed_at timestamptz NOT NULL,
                CONSTRAINT daily_covers_revisions_venue_same_org FOREIGN KEY (venue_id, org_id)
                    REFERENCES venues (id, org_id) ON DELETE CASCADE
            )
        SQL);

        // One row per POS category per day, as the POS reported it; food/drinks/other are derived
        // from these with the mapping, so a remap needs no new pull.
        DB::statement(<<<'SQL'
            CREATE TABLE sales_daily_categories (
                org_id uuid NOT NULL,
                venue_id uuid NOT NULL,
                business_date date NOT NULL,
                source text NOT NULL CHECK (source IN ('square')),
                category_key text NOT NULL CHECK (length(category_key) BETWEEN 1 AND 200),
                category_name text NOT NULL CHECK (length(category_name) <= 200),
                net_cents bigint NOT NULL,
                quantity numeric(14,3) NOT NULL DEFAULT 0,
                PRIMARY KEY (venue_id, business_date, source, category_key),
                CONSTRAINT sales_daily_categories_venue_same_org FOREIGN KEY (venue_id, org_id)
                    REFERENCES venues (id, org_id) ON DELETE CASCADE
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE category_mappings (
                org_id uuid NOT NULL,
                venue_id uuid NOT NULL,
                source text NOT NULL CHECK (source IN ('square')),
                category_key text NOT NULL CHECK (length(category_key) BETWEEN 1 AND 200),
                kind text NOT NULL CHECK (kind IN ('food', 'drinks', 'other')),
                mapped_by bigint NULL REFERENCES users(id),
                mapped_at timestamptz NOT NULL,
                PRIMARY KEY (venue_id, source, category_key),
                CONSTRAINT category_mappings_venue_same_org FOREIGN KEY (venue_id, org_id)
                    REFERENCES venues (id, org_id) ON DELETE CASCADE
            )
        SQL);

        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY {$table}_tenant_isolation ON {$table}
                USING (org_id::text = current_setting('app.current_org_id', true))
                WITH CHECK (org_id::text = current_setting('app.current_org_id', true))");
        }
        // Covers can be cleared (a mistaken entry); the revision keeps the history. Re-syncs replace a
        // window of category rows.
        DB::statement('GRANT DELETE ON daily_covers, sales_daily_categories TO app_user');
        DB::statement('REVOKE UPDATE ON daily_covers_revisions FROM app_user');
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            DB::statement("DROP TABLE {$table}");
        }
        DB::statement('ALTER TABLE sales_daily DROP CONSTRAINT sales_daily_split_complete');
        DB::statement('ALTER TABLE sales_daily DROP COLUMN food_cents, DROP COLUMN drinks_cents, DROP COLUMN other_cents');
        DB::statement('ALTER TABLE sales_daily_revisions
            DROP COLUMN new_food_cents, DROP COLUMN new_drinks_cents, DROP COLUMN new_other_cents,
            DROP COLUMN old_food_cents, DROP COLUMN old_drinks_cents, DROP COLUMN old_other_cents');
    }
};
