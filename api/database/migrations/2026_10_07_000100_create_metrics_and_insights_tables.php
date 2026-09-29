<?php
// api/database/migrations/2026_10_07_000100_create_metrics_and_insights_tables.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Own performance out (Plan C design §3.7 and §3.9). Both tables are rebuilt from sales_daily and
 * only ever upserted, so app_user needs no DELETE. Money is GST-inclusive cents.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE daily_venue_metrics (
                org_id uuid NOT NULL,
                venue_id uuid NOT NULL,
                business_date date NOT NULL,
                revenue_cents bigint NOT NULL,
                same_weekday_last_week_cents bigint NULL,
                same_weekday_last_year_cents bigint NULL,
                rolling_7_cents bigint NULL,
                rolling_7_prev_cents bigint NULL,
                rolling_7_ly_cents bigint NULL,
                rolling_28_cents bigint NULL,
                rolling_28_prev_cents bigint NULL,
                rolling_28_ly_cents bigint NULL,
                growth_index numeric(10, 2) NULL,
                updated_at timestamptz NOT NULL,
                PRIMARY KEY (venue_id, business_date),
                CONSTRAINT daily_venue_metrics_venue_same_org FOREIGN KEY (venue_id, org_id)
                    REFERENCES venues (id, org_id) ON DELETE CASCADE
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE insights (
                id uuid PRIMARY KEY,
                org_id uuid NOT NULL,
                venue_id uuid NOT NULL,
                as_of date NOT NULL,
                findings jsonb NOT NULL,
                findings_hash text NOT NULL,
                rules_version integer NOT NULL,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NULL,
                CONSTRAINT insights_venue_as_of_unique UNIQUE (venue_id, as_of),
                CONSTRAINT insights_venue_same_org FOREIGN KEY (venue_id, org_id)
                    REFERENCES venues (id, org_id) ON DELETE CASCADE
            )
        SQL);

        foreach (['daily_venue_metrics', 'insights'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY {$table}_tenant_isolation ON {$table}
                USING (org_id::text = current_setting('app.current_org_id', true))
                WITH CHECK (org_id::text = current_setting('app.current_org_id', true))");
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE insights');
        DB::statement('DROP TABLE daily_venue_metrics');
    }
};
