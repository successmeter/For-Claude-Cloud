<?php
// api/database/migrations/2026_09_24_100000_enable_rls_on_orgs_table.php
//
// IMPORTANT finding #3 (final whole-branch review): the 120000 migration granted
// app_user SELECT/INSERT/UPDATE on orgs with no RLS policy at all -- a session
// holding org A's tenant context could, in principle, SELECT/UPDATE any org's row if
// any future application bug reached that query directly. No code path does today
// (RegisterController is the only writer, and it only ever touches the org it just
// created), but the plan's own claims (05-security.md-driven §5.4/§5.9, echoed in
// this plan's Architecture note) that RLS holds "even if application code forgets a
// WHERE" wasn't true for orgs as shipped.
//
// This is a NEW migration rather than an edit to the already-applied 120000
// migration: Postgres migrations are forward-only once run in a real environment.
//
// users deliberately does NOT get an RLS policy here (and isn't in scope for this
// migration) -- it has no org_id to scope by (a user can belong to more than one
// org via memberships), so there's no single-column tenant boundary to enforce at
// the row level the way there is for orgs/venues/memberships.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE orgs ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE orgs FORCE ROW LEVEL SECURITY');

        // Read/update/delete are scoped to the org matching the current tenant
        // context, same shape as venues/memberships (Task 3's
        // {venues,memberships}_tenant_isolation policies). Each needs its own
        // CREATE POLICY statement -- Postgres's FOR clause only accepts a single
        // command (or ALL), not a list -- and a combined FOR ALL policy would also
        // gate INSERT via its USING clause (used as the implicit WITH CHECK when
        // none is given), which is exactly what the INSERT policy below must avoid.
        DB::statement("CREATE POLICY orgs_tenant_isolation_select ON orgs
            FOR SELECT USING (id::text = current_setting('app.current_org_id', true))");

        DB::statement("CREATE POLICY orgs_tenant_isolation_update ON orgs
            FOR UPDATE
            USING (id::text = current_setting('app.current_org_id', true))
            WITH CHECK (id::text = current_setting('app.current_org_id', true))");

        DB::statement("CREATE POLICY orgs_tenant_isolation_delete ON orgs
            FOR DELETE USING (id::text = current_setting('app.current_org_id', true))");

        // Registration inserts a brand-new org BEFORE any tenant context exists for
        // it: RegisterController creates the Org row first, then calls
        // TenantContext::set($org->id) -- there is no "current org" to check the new
        // row's id against yet. A separate permissive FOR INSERT policy with
        // WITH CHECK (true) allows any INSERT unconditionally. This is safe despite
        // being unconditional: an INSERT can only create a brand-new row under a
        // fresh id (typically a UUIDv7 from HasUuids), never modify or expose an
        // existing org's row -- the SELECT/UPDATE/DELETE policies above still fully
        // gate access to every other org's existing data.
        DB::statement('CREATE POLICY orgs_insert_new_org ON orgs
            FOR INSERT WITH CHECK (true)');
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS orgs_insert_new_org ON orgs');
        DB::statement('DROP POLICY IF EXISTS orgs_tenant_isolation_delete ON orgs');
        DB::statement('DROP POLICY IF EXISTS orgs_tenant_isolation_update ON orgs');
        DB::statement('DROP POLICY IF EXISTS orgs_tenant_isolation_select ON orgs');

        DB::statement('ALTER TABLE orgs NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE orgs DISABLE ROW LEVEL SECURITY');
    }
};
