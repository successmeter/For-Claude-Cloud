<?php
// api/tests/Feature/Tenancy/MembershipPolicyTest.php
namespace Tests\Feature\Tenancy;

use App\Models\Membership;
use App\Models\Org;
use App\Models\User;
use App\Models\Venue;
use App\Services\Tenancy\TenantContext;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\TestCase;

class MembershipPolicyTest extends TestCase
{
    use RefreshesPrivilegedDatabase;

    private function makeMember(string $role): array
    {
        $org = Org::create(['name' => 'Org']);

        // The memberships table has row-level security (Plan A Task 3): its USING/WITH
        // CHECK clause is `org_id::text = current_setting('app.current_org_id', true)`.
        // Without setting tenant context first, the Membership::create() below would be
        // rejected by WITH CHECK, and VenuePolicy::roleFor()'s SELECT would later be
        // silently filtered to zero rows by USING regardless of whether the row exists —
        // so every policy check would incorrectly return false, including the owner case.
        // Setting it once here, before creating the venue and membership, covers both.
        TenantContext::set($org->id);

        $venue = Venue::create(['org_id' => $org->id, 'name' => 'V', 'segment' => 'cafe']);
        $user = User::factory()->create();
        Membership::create(['org_id' => $org->id, 'user_id' => $user->id, 'role' => $role]);

        return [$user, $venue];
    }

    public function test_owner_can_update_and_delete_a_venue(): void
    {
        [$user, $venue] = $this->makeMember('owner');
        $this->assertTrue($user->can('update', $venue));
        $this->assertTrue($user->can('delete', $venue));
    }

    public function test_manager_can_update_but_not_delete_a_venue(): void
    {
        [$user, $venue] = $this->makeMember('manager');
        $this->assertTrue($user->can('update', $venue));
        $this->assertFalse($user->can('delete', $venue));
    }

    public function test_viewer_cannot_update_or_delete_a_venue(): void
    {
        [$user, $venue] = $this->makeMember('viewer');
        $this->assertFalse($user->can('update', $venue));
        $this->assertFalse($user->can('delete', $venue));
    }

    public function test_a_user_with_no_membership_has_no_access(): void
    {
        $org = Org::create(['name' => 'Org']);
        TenantContext::set($org->id);
        $venue = Venue::create(['org_id' => $org->id, 'name' => 'V', 'segment' => 'cafe']);
        $user = User::factory()->create();

        $this->assertFalse($user->can('update', $venue));
    }
}
