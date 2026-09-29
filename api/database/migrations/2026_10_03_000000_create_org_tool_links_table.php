<?php
// api/database/migrations/2026_10_03_000000_create_org_tool_links_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Which other tools an org has enabled (design §4.4). A tool's client-credentials token can only
 * reach orgs with an active link, and external_tenant_ref is the tool's own id for the org (the
 * Web tool's tenants.id), opaque to the Hub.
 *
 * app_user gets SELECT/INSERT/UPDATE from the default privileges (Task 4). No DELETE: a link is
 * ended by setting revoked_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE org_tool_links (
                id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                org_id uuid NOT NULL REFERENCES orgs(id),
                tool text NOT NULL CHECK (tool IN ('web')),
                external_tenant_ref text NOT NULL CHECK (length(external_tenant_ref) BETWEEN 1 AND 200),
                linked_by bigint NULL REFERENCES users(id),
                linked_at timestamptz NOT NULL,
                revoked_at timestamptz NULL,
                created_at timestamptz NULL,
                updated_at timestamptz NULL,
                UNIQUE (org_id, tool)
            )
        SQL);

        DB::statement('ALTER TABLE org_tool_links ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE org_tool_links FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY org_tool_links_tenant_isolation ON org_tool_links
            USING (org_id::text = current_setting('app.current_org_id', true))
            WITH CHECK (org_id::text = current_setting('app.current_org_id', true))");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE org_tool_links');
    }
};
