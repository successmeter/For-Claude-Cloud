<?php
// api/tests/Support/SpaRequests.php

namespace Tests\Support;

use App\Models\Membership;
use App\Models\Org;
use App\Models\User;
use App\Models\Venue;
use App\Services\Tenancy\TenantContext;
use Illuminate\Testing\TestResponse;

/**
 * First-party (React app) requests: a signed-in user plus the X-Hub-Org header, as the SPA sends
 * them (Plan B: one tenant mechanism for every caller).
 */
trait SpaRequests
{
    protected function org(string $name = 'Org'): Org
    {
        return Org::create(['name' => $name]);
    }

    protected function member(Org $org, string $role = 'owner'): User
    {
        $user = User::factory()->create();
        TenantContext::run($org->id, fn () => Membership::create([
            'org_id' => $org->id, 'user_id' => $user->id, 'role' => $role,
        ]));

        return $user;
    }

    protected function venue(Org $org, array $attributes = []): Venue
    {
        return TenantContext::run($org->id, fn () => Venue::create($attributes + [
            'org_id' => $org->id, 'name' => 'Cafe', 'segment' => 'cafe',
        ]));
    }

    protected function spa(User $user, Org $org, string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->actingAs($user)->json($method, $uri, $data, ['X-Hub-Org' => $org->id] + $headers);
    }

    /** Reads a tenant table outside a request, as the app would. */
    protected function inTenant(Org $org, \Closure $fn): mixed
    {
        return TenantContext::run($org->id, $fn);
    }
}
