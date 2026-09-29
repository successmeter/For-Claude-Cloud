<?php
// api/tests/Feature/Tenancy/SchemaSecurityCatalogTest.php

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\TestCase;

/**
 * Reads the live Postgres catalog so a new table cannot silently miss RLS or pick up a dangerous
 * grant (Plan A Open Item 4: the per-table checklist already failed once, for
 * personal_access_tokens). Adding a table to either list below needs a written reason.
 */
class SchemaSecurityCatalogTest extends TestCase
{
    use RefreshesPrivilegedDatabase;

    /** Tables with an org_id column that deliberately have no row-level security. */
    private const ALLOWLIST_NO_RLS = [
        'audit_log' => 'append-only: app_user has INSERT only, so there is nothing to read or change through it',
    ];

    /**
     * Tables app_user may DELETE from. DELETE is never granted by default: Postgres runs
     * cascading FK actions with row security disabled, so DELETE on a parent table can reach
     * other orgs' rows (see the Plan A RLS migration).
     */
    private const ALLOWED_DELETE = [
        'venues' => 'RLS-scoped; nothing cascades from it across orgs',
        'memberships' => 'RLS-scoped; nothing cascades from it across orgs',
        'password_reset_tokens' => 'framework table, no tenant data',
        'sessions' => 'framework table, no tenant data',
        'cache' => 'framework table, no tenant data',
        'cache_locks' => 'framework table, no tenant data',
        'jobs' => 'framework table, no tenant data',
        'job_batches' => 'framework table, no tenant data',
        'failed_jobs' => 'framework table, no tenant data',
        'personal_access_tokens' => 'Sanctum tokens; revocation deletes rows',
    ];

    public function test_every_org_scoped_table_has_forced_rls_and_a_policy(): void
    {
        $rows = DB::connection('pgsql')->select(<<<'SQL'
            SELECT c.relname AS name, c.relrowsecurity AS rls, c.relforcerowsecurity AS forced,
                   (SELECT count(*) FROM pg_policies p WHERE p.schemaname = 'public' AND p.tablename = c.relname) AS policies
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = 'public'
            WHERE c.relkind = 'r'
              AND EXISTS (SELECT 1 FROM pg_attribute a WHERE a.attrelid = c.oid AND a.attname = 'org_id' AND NOT a.attisdropped)
        SQL);

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            if (array_key_exists($row->name, self::ALLOWLIST_NO_RLS)) {
                continue;
            }
            $this->assertTrue($row->rls && $row->forced, "{$row->name} has org_id but RLS is not enabled and forced");
            $this->assertGreaterThan(0, $row->policies, "{$row->name} has org_id but no RLS policy");
        }
    }

    public function test_app_user_delete_grants_are_all_accounted_for(): void
    {
        $withDelete = collect(DB::connection('pgsql')->select(<<<'SQL'
            WITH t AS MATERIALIZED (
                SELECT c.oid, c.relname FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace AND n.nspname = 'public'
                WHERE c.relkind = 'r'
            )
            SELECT relname FROM t WHERE has_table_privilege('app_user', oid, 'DELETE')
        SQL))->pluck('relname')->sort()->values()->all();

        $unexpected = array_diff($withDelete, array_keys(self::ALLOWED_DELETE));
        $this->assertSame([], array_values($unexpected), 'app_user has DELETE on tables not in ALLOWED_DELETE');
    }

    public function test_new_tables_get_select_insert_update_but_not_delete_by_default(): void
    {
        $pgsql = DB::connection('pgsql');
        $pgsql->beginTransaction();
        try {
            $pgsql->statement('CREATE TABLE probe_default_privileges (id int)');
            $can = fn (string $privilege) => $pgsql->selectOne(
                "SELECT has_table_privilege('app_user', 'public.probe_default_privileges', ?) AS ok",
                [$privilege],
            )->ok;

            $this->assertTrue($can('SELECT'));
            $this->assertTrue($can('INSERT'));
            $this->assertTrue($can('UPDATE'));
            $this->assertFalse($can('DELETE'));
        } finally {
            $pgsql->rollBack();
        }
    }
}
