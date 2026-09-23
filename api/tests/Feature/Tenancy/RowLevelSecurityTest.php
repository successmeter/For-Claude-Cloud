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
}
