<?php
// api/tests/Feature/Tenancy/ResolveTenantTest.php

namespace Tests\Feature\Tenancy;

use App\Models\Membership;
use App\Models\Org;
use App\Models\User;
use App\Models\Venue;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\TestCase;

class ResolveTenantTest extends TestCase
{
    use RefreshesPrivilegedDatabase;

    private Org $orgA;

    private Org $orgB;

    private Venue $venueA;

    private Venue $venueB;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum', 'tenant'])->group(function () {
            Route::get('/api/_probe', fn () => [
                'org' => request()->attributes->get('hub.org_id'),
                'role' => request()->attributes->get('hub.role'),
                'venues' => DB::table('venues')->pluck('name')->all(),
            ]);
            // Route-model binding runs in SubstituteBindings; tenant context must already be
            // set by then or the RLS-filtered lookup 404s (see bootstrap/app.php).
            Route::get('/api/_probe/venues/{venue}', fn (Venue $venue) => ['name' => $venue->name]);
        });

        $this->orgA = Org::create(['name' => 'A']);
        $this->orgB = Org::create(['name' => 'B']);
        $this->member = User::factory()->create();

        TenantContext::run($this->orgA->id, function () {
            $this->venueA = Venue::create(['org_id' => $this->orgA->id, 'name' => 'VA', 'segment' => 'cafe']);
            Membership::create(['org_id' => $this->orgA->id, 'user_id' => $this->member->id, 'role' => 'manager']);
        });
        TenantContext::run($this->orgB->id, function () {
            $this->venueB = Venue::create(['org_id' => $this->orgB->id, 'name' => 'VB', 'segment' => 'cafe']);
        });
    }

    public function test_member_gets_their_org_context_and_role(): void
    {
        $this->actingAs($this->member)
            ->getJson('/api/_probe', ['X-Hub-Org' => $this->orgA->id])
            ->assertOk()
            ->assertJson(['org' => $this->orgA->id, 'role' => 'manager', 'venues' => ['VA']]);

        $this->assertNull(TenantContext::current(), 'tenant context must not outlive the request');
    }

    public function test_bound_model_resolves_inside_the_tenant(): void
    {
        $this->actingAs($this->member)
            ->getJson('/api/_probe/venues/'.$this->venueA->id, ['X-Hub-Org' => $this->orgA->id])
            ->assertOk()
            ->assertJson(['name' => 'VA']);
    }

    public function test_bound_model_from_another_org_is_not_found(): void
    {
        $this->actingAs($this->member)
            ->getJson('/api/_probe/venues/'.$this->venueB->id, ['X-Hub-Org' => $this->orgA->id])
            ->assertNotFound();
    }

    public function test_non_member_org_is_404_not_403(): void
    {
        $this->actingAs($this->member)
            ->getJson('/api/_probe', ['X-Hub-Org' => $this->orgB->id])
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['type' => 'https://hub/problems/org_not_found', 'status' => 404]);
    }

    public function test_missing_header_is_400(): void
    {
        $this->actingAs($this->member)
            ->getJson('/api/_probe')
            ->assertStatus(400)
            ->assertJson(['type' => 'https://hub/problems/org_required']);
    }

    public function test_non_uuid_header_is_404(): void
    {
        $this->actingAs($this->member)
            ->getJson('/api/_probe', ['X-Hub-Org' => 'not-a-uuid'])
            ->assertNotFound();
    }

    public function test_old_x_org_id_header_is_ignored(): void
    {
        $this->actingAs($this->member)
            ->getJson('/api/_probe', ['X-Org-Id' => $this->orgA->id])
            ->assertStatus(400);
    }

    public function test_unauthenticated_request_is_rejected_before_tenant_resolution(): void
    {
        $this->getJson('/api/_probe', ['X-Hub-Org' => $this->orgA->id])->assertUnauthorized();
    }
}
