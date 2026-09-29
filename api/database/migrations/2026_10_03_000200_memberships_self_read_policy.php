<?php
// api/database/migrations/2026_10_03_000200_memberships_self_read_policy.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * GET /hub/v1/me/orgs lists one user's orgs across tenants, which the one-org RLS policies cannot
 * do. These permissive, SELECT-only policies key off a second transaction-local setting,
 * app.current_user_id (TenantContext::runAsUser()): a user can read their own memberships and the
 * orgs they belong to, nothing of anyone else, and cannot write through them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE POLICY memberships_self_read ON memberships FOR SELECT
            USING (user_id::text = current_setting('app.current_user_id', true))");
        DB::statement("CREATE POLICY orgs_member_read ON orgs FOR SELECT
            USING (id IN (SELECT org_id FROM memberships
                          WHERE user_id::text = current_setting('app.current_user_id', true)))");
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS orgs_member_read ON orgs');
        DB::statement('DROP POLICY IF EXISTS memberships_self_read ON memberships');
    }
};
