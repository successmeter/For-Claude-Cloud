<?php
// api/tests/Feature/Auth/EnsureOwnerHasMfaTest.php

namespace Tests\Feature\Auth;

use App\Models\Membership;
use App\Models\Org;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\TestCase;

class EnsureOwnerHasMfaTest extends TestCase
{
    use RefreshesPrivilegedDatabase;

    private Org $org;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum', 'tenant', 'mfa.owner'])
            ->post('/api/_owner_only', fn () => ['ok' => true]);

        $this->org = Org::create(['name' => 'Org']);
    }

    private function member(string $role, bool $mfa): User
    {
        $user = User::factory()->create();
        $user->forceFill(['mfa_enabled' => $mfa, 'mfa_secret' => $mfa ? 'JBSWY3DPEHPK3PXP' : null])->save();
        TenantContext::run($this->org->id, fn () => Membership::create([
            'org_id' => $this->org->id, 'user_id' => $user->id, 'role' => $role,
        ]));

        return $user;
    }

    public function test_owner_without_mfa_is_refused(): void
    {
        $this->actingAs($this->member('owner', false))
            ->postJson('/api/_owner_only', [], ['X-Hub-Org' => $this->org->id])
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['type' => 'https://hub/problems/mfa_required']);
    }

    public function test_owner_with_mfa_passes(): void
    {
        $this->actingAs($this->member('owner', true))
            ->postJson('/api/_owner_only', [], ['X-Hub-Org' => $this->org->id])
            ->assertOk();
    }

    public function test_manager_without_mfa_passes_because_the_rule_binds_owners_only(): void
    {
        $this->actingAs($this->member('manager', false))
            ->postJson('/api/_owner_only', [], ['X-Hub-Org' => $this->org->id])
            ->assertOk();
    }
}
