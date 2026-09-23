<?php
// api/tests/Feature/Tenancy/TenancyModelsTest.php

namespace Tests\Feature\Tenancy;

use App\Models\Membership;
use App\Models\Org;
use App\Models\User;
use App\Models\Venue;
use App\Services\Tenancy\TenantContext;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\TestCase;

class TenancyModelsTest extends TestCase
{
    use RefreshesPrivilegedDatabase;

    public function test_org_has_many_venues(): void
    {
        $org = Org::create(['name' => 'Test Org']);

        // Since Plan A Task 3, venues/memberships are protected by row-level security
        // (see RowLevelSecurityTest) whose WITH CHECK clause requires a matching tenant
        // context on every write, not just every read — so this pre-existing test needs
        // to act "as" the org it's writing for, same as production code would via the
        // SetTenantContext middleware.
        TenantContext::set($org->id);
        $venue = Venue::create([
            'org_id' => $org->id,
            'name' => 'Test Venue',
            'timezone' => 'Australia/Perth',
            'segment' => 'restaurant',
            'cuisine' => 'italian',
        ]);

        $this->assertTrue($org->venues->contains($venue));
        $this->assertEquals($org->id, $venue->org_id);
    }

    public function test_user_membership_has_a_role(): void
    {
        $org = Org::create(['name' => 'Test Org']);
        $user = User::factory()->create();

        TenantContext::set($org->id);
        $membership = Membership::create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $this->assertTrue($user->memberships->contains($membership));
        $this->assertEquals('owner', $membership->role);
    }

    public function test_membership_role_is_restricted_to_known_values(): void
    {
        $org = Org::create(['name' => 'Test Org']);
        $user = User::factory()->create();

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Tenant context is set to the right org so the exception below is actually the
        // role CHECK constraint this test targets, not an RLS rejection for a different
        // reason.
        TenantContext::set($org->id);
        Membership::create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'superadmin', // not a valid role
        ]);
    }
}
