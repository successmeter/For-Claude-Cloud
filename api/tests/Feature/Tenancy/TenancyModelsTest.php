<?php
// api/tests/Feature/Tenancy/TenancyModelsTest.php

namespace Tests\Feature\Tenancy;

use App\Models\Membership;
use App\Models\Org;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenancyModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_org_has_many_venues(): void
    {
        $org = Org::create(['name' => 'Test Org']);
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

        Membership::create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'superadmin', // not a valid role
        ]);
    }
}
