<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan E design §2.5: one Square connection per org, tokens encrypted with the org's data key, and
 * per-venue location links. Disconnecting deletes the connection (and its links), so both tables
 * grant DELETE; neither holds history.
 */
return new class extends Migration
{
    private const TABLES = ['pos_connections', 'pos_location_links'];

    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE pos_connections (
                id uuid PRIMARY KEY,
                org_id uuid NOT NULL REFERENCES orgs(id) ON DELETE CASCADE,
                provider text NOT NULL CHECK (provider IN ('square')),
                merchant_id text NOT NULL CHECK (length(merchant_id) BETWEEN 1 AND 200),
                access_token_enc text NOT NULL,
                refresh_token_enc text NULL,
                token_expires_at timestamptz NULL,
                scopes text NOT NULL,
                status text NOT NULL CHECK (status IN ('connected', 'needs_reauth')),
                consecutive_failures integer NOT NULL DEFAULT 0,
                paused_at timestamptz NULL,
                last_synced_at timestamptz NULL,
                last_error text NULL CHECK (length(last_error) <= 500),
                connected_by bigint NULL REFERENCES users(id),
                created_at timestamptz NOT NULL,
                updated_at timestamptz NOT NULL,
                CONSTRAINT pos_connections_one_per_provider UNIQUE (org_id, provider),
                CONSTRAINT pos_connections_id_org_unique UNIQUE (id, org_id)
            )
        SQL);
        DB::statement(<<<'SQL'
            CREATE TABLE pos_location_links (
                org_id uuid NOT NULL,
                connection_id uuid NOT NULL,
                location_id text NOT NULL CHECK (length(location_id) BETWEEN 1 AND 200),
                location_name text NOT NULL CHECK (length(location_name) <= 200),
                venue_id uuid NOT NULL,
                backfilled_at timestamptz NULL,
                last_synced_at timestamptz NULL,
                linked_by bigint NULL REFERENCES users(id),
                created_at timestamptz NOT NULL,
                PRIMARY KEY (connection_id, location_id),
                CONSTRAINT pos_location_links_one_per_venue UNIQUE (venue_id),
                CONSTRAINT pos_location_links_connection_same_org FOREIGN KEY (connection_id, org_id)
                    REFERENCES pos_connections (id, org_id) ON DELETE CASCADE,
                CONSTRAINT pos_location_links_venue_same_org FOREIGN KEY (venue_id, org_id)
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
        DB::statement('GRANT DELETE ON pos_connections, pos_location_links TO app_user');
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            DB::statement("DROP TABLE {$table}");
        }
    }
};
