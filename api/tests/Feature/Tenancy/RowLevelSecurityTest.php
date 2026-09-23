<?php
// api/tests/Feature/Tenancy/RowLevelSecurityTest.php

namespace Tests\Feature\Tenancy;

use App\Models\Org;
use App\Models\Venue;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\TestCase;

class RowLevelSecurityTest extends TestCase
{
    use RefreshesPrivilegedDatabase;

    public function test_venues_are_isolated_by_org_at_the_database_level(): void
    {
        $orgA = Org::create(['name' => 'Org A']);
        $orgB = Org::create(['name' => 'Org B']);

        // The venues policy's WITH CHECK clause enforces tenant isolation on writes too
        // (org_id::text = current_setting('app.current_org_id', true)), and that setting
        // is NULL, not an empty string, until TenantContext::set() has been called at
        // least once in this session — NULL comparisons are never true in Postgres, so an
        // insert attempted with no context set is itself rejected by RLS. That mirrors
        // real app behaviour (the app only ever writes a venue while acting for a specific
        // org), so each venue below is created under its own org's context rather than
        // with no context set.
        TenantContext::set($orgA->id);
        Venue::create(['org_id' => $orgA->id, 'name' => 'A Venue', 'segment' => 'cafe']);

        TenantContext::set($orgB->id);
        Venue::create(['org_id' => $orgB->id, 'name' => 'B Venue', 'segment' => 'cafe']);

        TenantContext::set($orgA->id);
        // Raw query, bypassing any Eloquent global scope, to prove the DB itself enforces isolation.
        $rows = DB::select('select name from venues');
        $this->assertCount(1, $rows);
        $this->assertEquals('A Venue', $rows[0]->name);

        TenantContext::set($orgB->id);
        $rows = DB::select('select name from venues');
        $this->assertCount(1, $rows);
        $this->assertEquals('B Venue', $rows[0]->name);
    }

    public function test_no_tenant_context_means_no_rows_visible(): void
    {
        $org = Org::create(['name' => 'Org A']);

        // Create the venue under its own org's context (see comment in the test above),
        // then clear the context before asserting fail-closed SELECT behaviour.
        TenantContext::set($org->id);
        Venue::create(['org_id' => $org->id, 'name' => 'A Venue', 'segment' => 'cafe']);

        TenantContext::clear();
        $rows = DB::select('select name from venues');
        $this->assertCount(0, $rows, 'RLS must fail closed with no tenant context set');
    }

    public function test_inserting_a_venue_for_another_org_is_rejected_by_rls_with_check(): void
    {
        $orgA = Org::create(['name' => 'Org A']);
        $orgB = Org::create(['name' => 'Org B']);

        // Under org A's tenant context, attempt to insert a venue whose org_id belongs to
        // org B. The venues_tenant_isolation policy's WITH CHECK clause must reject this
        // (org_id::text = current_setting('app.current_org_id', true) is false), proving
        // RLS enforces isolation on writes, not just reads.
        TenantContext::set($orgA->id);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/row.level security/i');

        Venue::create(['org_id' => $orgB->id, 'name' => 'Smuggled Venue', 'segment' => 'cafe']);
    }

    public function test_app_user_cannot_delete_orgs(): void
    {
        // Critical regression test: app_user must not hold DELETE on orgs. Without this,
        // a session holding org A's tenant context could run DELETE FROM orgs WHERE id =
        // '<org B>' — that statement runs as a plain (non-RLS-scoped) DELETE against a
        // table with no RLS policy, and Postgres executes cascadeOnDelete() FK cascades
        // to venues/memberships with row security disabled, silently destroying org B's
        // data despite the RLS policies on venues/memberships being otherwise correct.
        $org = Org::create(['name' => 'Org To Delete']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/permission denied/i');

        DB::statement('delete from orgs where id = ?', [$org->id]);
    }

    public function test_app_user_cannot_delete_users(): void
    {
        // Same regression as above, for the users table.
        $user = \App\Models\User::create([
            'name' => 'Someone',
            'email' => 'someone@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/permission denied/i');

        DB::statement('delete from users where id = ?', [$user->id]);
    }
}
