<?php
// api/database/migrations/2026_10_07_000000_create_sales_tables.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sales data in (Plan C design §3.2-3.6): upload runs, their staged rows, encrypted snapshots,
 * canonical daily sales and its revision history.
 *
 * Every table references venues through (venue_id, org_id), so a row can never hang off another
 * org's venue, and deleting a venue (the one ON DELETE CASCADE here) only reaches its own org's
 * rows even though Postgres runs cascades with row security off.
 *
 * DELETE for app_user: ingestion_run_rows (staging is cleared on commit, discard and expiry) and
 * source_snapshots (pruned after 90 days). Both are RLS-scoped and nothing cascades from them.
 * sales_daily_revisions is append-only: UPDATE revoked, and DELETE is never granted.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE venues ADD CONSTRAINT venues_id_org_unique UNIQUE (id, org_id)');

        DB::statement(<<<'SQL'
            CREATE TABLE ingestion_runs (
                id uuid PRIMARY KEY,
                org_id uuid NOT NULL REFERENCES orgs(id),
                venue_id uuid NOT NULL,
                method text NOT NULL CHECK (method IN ('upload', 'api', 'intermediary')),
                status text NOT NULL CHECK (status IN ('previewed', 'committed', 'discarded', 'expired')),
                created_by bigint NULL REFERENCES users(id),
                file_sha256 text NULL,
                file_bytes integer NULL,
                row_count integer NULL,
                mapping jsonb NULL,
                summary jsonb NULL,
                problems jsonb NULL,
                basis_revision bigint NOT NULL DEFAULT 0,
                created_at timestamptz NOT NULL,
                updated_at timestamptz NULL,
                committed_at timestamptz NULL,
                expires_at timestamptz NULL,
                CONSTRAINT ingestion_runs_id_org_unique UNIQUE (id, org_id),
                CONSTRAINT ingestion_runs_venue_same_org FOREIGN KEY (venue_id, org_id)
                    REFERENCES venues (id, org_id) ON DELETE CASCADE
            )
        SQL);
        DB::statement('CREATE INDEX ingestion_runs_venue_idx ON ingestion_runs (venue_id, created_at DESC)');
        DB::statement("CREATE INDEX ingestion_runs_open_idx ON ingestion_runs (expires_at) WHERE status = 'previewed'");

        DB::statement(<<<'SQL'
            CREATE TABLE ingestion_run_rows (
                run_id uuid NOT NULL,
                org_id uuid NOT NULL,
                business_date date NOT NULL,
                revenue_cents bigint NOT NULL CHECK (revenue_cents >= 0),
                gst_inclusive boolean NOT NULL,
                tx_count integer NULL CHECK (tx_count >= 0),
                change text NOT NULL CHECK (change IN ('new', 'changed', 'unchanged')),
                PRIMARY KEY (run_id, business_date),
                CONSTRAINT ingestion_run_rows_run_same_org FOREIGN KEY (run_id, org_id)
                    REFERENCES ingestion_runs (id, org_id) ON DELETE CASCADE
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE source_snapshots (
                id uuid PRIMARY KEY,
                org_id uuid NOT NULL,
                run_id uuid NOT NULL,
                path text NOT NULL,
                sha256 text NOT NULL,
                bytes integer NOT NULL,
                created_at timestamptz NOT NULL,
                expires_at timestamptz NOT NULL,
                CONSTRAINT source_snapshots_run_same_org FOREIGN KEY (run_id, org_id)
                    REFERENCES ingestion_runs (id, org_id) ON DELETE CASCADE
            )
        SQL);
        DB::statement('CREATE INDEX source_snapshots_expiry_idx ON source_snapshots (expires_at)');

        DB::statement('CREATE SEQUENCE sales_daily_revision_seq');
        DB::statement(<<<'SQL'
            CREATE TABLE sales_daily (
                org_id uuid NOT NULL,
                venue_id uuid NOT NULL,
                business_date date NOT NULL,
                revenue_cents bigint NOT NULL CHECK (revenue_cents >= 0),
                gst_inclusive boolean NOT NULL,
                -- Metrics use GST-inclusive revenue (decision C3). x1.1 assumes the day's sales were all taxable.
                revenue_inc_gst_cents bigint GENERATED ALWAYS AS (
                    CASE WHEN gst_inclusive THEN revenue_cents ELSE round(revenue_cents * 1.1)::bigint END
                ) STORED,
                tx_count integer NULL CHECK (tx_count >= 0),
                source text NOT NULL CHECK (source IN ('upload', 'pos', 'intermediary')),
                ingestion_run_id uuid NULL REFERENCES ingestion_runs(id),
                revision bigint NOT NULL DEFAULT nextval('sales_daily_revision_seq'),
                created_at timestamptz NOT NULL,
                revised_at timestamptz NOT NULL,
                PRIMARY KEY (venue_id, business_date),
                CONSTRAINT sales_daily_venue_same_org FOREIGN KEY (venue_id, org_id)
                    REFERENCES venues (id, org_id) ON DELETE CASCADE
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE sales_daily_revisions (
                id bigserial PRIMARY KEY,
                org_id uuid NOT NULL,
                venue_id uuid NOT NULL,
                business_date date NOT NULL,
                old_revenue_cents bigint NOT NULL,
                old_gst_inclusive boolean NOT NULL,
                old_tx_count integer NULL,
                new_revenue_cents bigint NOT NULL,
                new_gst_inclusive boolean NOT NULL,
                new_tx_count integer NULL,
                ingestion_run_id uuid NULL REFERENCES ingestion_runs(id),
                revised_at timestamptz NOT NULL,
                CONSTRAINT sales_daily_revisions_venue_same_org FOREIGN KEY (venue_id, org_id)
                    REFERENCES venues (id, org_id) ON DELETE CASCADE
            )
        SQL);
        // Owned by the column, so dropping the table (e.g. migrate:fresh) drops the sequence too.
        DB::statement('ALTER SEQUENCE sales_daily_revision_seq OWNED BY sales_daily.revision');
        DB::statement('CREATE INDEX sales_daily_revisions_venue_idx ON sales_daily_revisions (venue_id, business_date)');

        foreach (['ingestion_runs', 'ingestion_run_rows', 'source_snapshots', 'sales_daily', 'sales_daily_revisions'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY {$table}_tenant_isolation ON {$table}
                USING (org_id::text = current_setting('app.current_org_id', true))
                WITH CHECK (org_id::text = current_setting('app.current_org_id', true))");
        }

        DB::statement('GRANT DELETE ON ingestion_run_rows, source_snapshots TO app_user');
        DB::statement('REVOKE UPDATE ON sales_daily_revisions FROM app_user');
    }

    public function down(): void
    {
        foreach (['sales_daily_revisions', 'sales_daily', 'source_snapshots', 'ingestion_run_rows', 'ingestion_runs'] as $table) {
            DB::statement("DROP TABLE {$table}");
        }
        DB::statement('ALTER TABLE venues DROP CONSTRAINT venues_id_org_unique');
    }
};
