<?php
// api/database/migrations/2026_09_23_120000_enable_rls_on_tenant_tables.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("DO $$ BEGIN
            IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'app_user') THEN
                CREATE ROLE app_user LOGIN PASSWORD 'app_user_password';
            END IF;
        END $$;");

        // Unconditional and idempotent, regardless of whether app_user was just created
        // above or already existed in the cluster (e.g. from a prior partial run, or
        // another project sharing this Postgres instance). A pre-existing app_user role
        // could otherwise carry elevated attributes (SUPERUSER, BYPASSRLS, etc.) that
        // would silently defeat RLS — this makes the role's safety properties explicit
        // every time this migration runs, not just on first creation.
        DB::statement('ALTER ROLE app_user NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE');

        DB::statement('GRANT USAGE ON SCHEMA public TO app_user');
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON venues, memberships TO app_user');
        DB::statement('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO app_user');

        DB::statement('ALTER TABLE venues ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE venues FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY venues_tenant_isolation ON venues
            USING (org_id::text = current_setting('app.current_org_id', true))
            WITH CHECK (org_id::text = current_setting('app.current_org_id', true))");

        DB::statement('ALTER TABLE memberships ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE memberships FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY memberships_tenant_isolation ON memberships
            USING (org_id::text = current_setting('app.current_org_id', true))
            WITH CHECK (org_id::text = current_setting('app.current_org_id', true))");

        // Not RLS-relevant, but required for the app to function at all once the app's
        // default DB connection (pgsql_app) points at the restricted app_user role instead
        // of the Sail superuser: orgs is the tenant registry itself (no org_id column to
        // scope by — every org row is, by definition, visible to the row's own tenant
        // bootstrap flow), so it needs ordinary CRUD grants but no RLS policy.
        //
        // Same reasoning extends to every other table the app touches at runtime through
        // the default connection: Laravel's own framework tables (auth, and the database
        // drivers this app is configured to use for sessions/cache/queue — see .env
        // SESSION_DRIVER, CACHE_STORE, QUEUE_CONNECTION). None of these carry an org_id
        // column, so none get an RLS policy, but without a plain grant app_user can't
        // read or write them at all and the app (and login, and Task 2's own
        // TenancyModelsTest, which creates a User) breaks outright.
        // orgs and users deliberately do NOT get DELETE: venues.org_id and
        // memberships.org_id/user_id are cascadeOnDelete() foreign keys, and Postgres
        // runs referential-integrity cascade actions with row security disabled. Granting
        // DELETE on orgs (or users) would let a session holding org A's tenant context run
        // `DELETE FROM orgs WHERE id = '<org B uuid>'`, which would succeed and cascade to
        // delete org B's venues/memberships entirely — bypassing the RLS policies above.
        // Nothing in this plan deletes an org or a user (orgs already has soft deletes for
        // that), so SELECT/INSERT/UPDATE is sufficient: registration needs INSERT on both.
        DB::statement('GRANT SELECT, INSERT, UPDATE ON orgs TO app_user');
        DB::statement('GRANT SELECT, INSERT, UPDATE ON users TO app_user');
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON password_reset_tokens, sessions TO app_user');
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON cache, cache_locks TO app_user');
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON jobs, job_batches, failed_jobs TO app_user');
    }

    public function down(): void
    {
        // Revoke everything up() granted, in roughly reverse order, before dropping the
        // policies/RLS flags they depend on. We deliberately do NOT `DROP ROLE app_user`
        // here: the role may be shared by other databases on this same Postgres cluster,
        // and dropping it is a cluster-wide operation this migration has no business doing
        // on a per-database rollback.
        DB::statement('REVOKE SELECT, INSERT, UPDATE, DELETE ON jobs, job_batches, failed_jobs FROM app_user');
        DB::statement('REVOKE SELECT, INSERT, UPDATE, DELETE ON cache, cache_locks FROM app_user');
        DB::statement('REVOKE SELECT, INSERT, UPDATE, DELETE ON password_reset_tokens, sessions FROM app_user');
        DB::statement('REVOKE SELECT, INSERT, UPDATE ON users FROM app_user');
        DB::statement('REVOKE SELECT, INSERT, UPDATE ON orgs FROM app_user');

        DB::statement('DROP POLICY IF EXISTS memberships_tenant_isolation ON memberships');
        DB::statement('DROP POLICY IF EXISTS venues_tenant_isolation ON venues');

        DB::statement('ALTER TABLE memberships NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE venues NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE memberships DISABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE venues DISABLE ROW LEVEL SECURITY');

        DB::statement('REVOKE USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public FROM app_user');
        DB::statement('REVOKE SELECT, INSERT, UPDATE, DELETE ON venues, memberships FROM app_user');
        DB::statement('REVOKE USAGE ON SCHEMA public FROM app_user');
    }
};
