<?php
// api/database/migrations/2026_10_09_000000_create_invitations_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Invitations (Plan D design §3.1): how people join an org during the invitation-only pilot. Only
 * the SHA-256 of the token's secret is stored. Rows are never deleted (revoked_at, accepted_at);
 * one open invitation per email per org.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE invitations (
                id uuid PRIMARY KEY,
                org_id uuid NOT NULL REFERENCES orgs(id) ON DELETE CASCADE,
                email text NOT NULL CHECK (length(email) BETWEEN 3 AND 254),
                role text NOT NULL CHECK (role IN ('owner', 'manager', 'viewer')),
                token_hash text NOT NULL UNIQUE,
                invited_by bigint NULL REFERENCES users(id),
                expires_at timestamptz NOT NULL,
                accepted_at timestamptz NULL,
                accepted_by bigint NULL REFERENCES users(id),
                revoked_at timestamptz NULL,
                created_at timestamptz NULL,
                updated_at timestamptz NULL
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX invitations_one_open_per_email ON invitations (org_id, lower(email)) WHERE accepted_at IS NULL AND revoked_at IS NULL');
        DB::statement('ALTER TABLE invitations ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE invitations FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY invitations_tenant_isolation ON invitations
            USING (org_id::text = current_setting('app.current_org_id', true))
            WITH CHECK (org_id::text = current_setting('app.current_org_id', true))");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE invitations');
    }
};
