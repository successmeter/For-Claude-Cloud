<?php
// api/tests/Feature/Hub/IdTokenClaimsTest.php

namespace Tests\Feature\Hub;

use App\Models\User;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\HubTokens;
use Tests\TestCase;

class IdTokenClaimsTest extends TestCase
{
    use HubTokens, RefreshesPrivilegedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openid.forceHttps' => false]);
    }

    private function claimsFor(User $user, string $scope): array
    {
        [$client, $secret] = $this->makeWebClient();
        $tokens = $this->authorizeAndExchange($user, $client, $secret, $scope);

        return $this->parseJwt($tokens['id_token'])->claims()->all();
    }

    public function test_openid_alone_carries_no_profile_or_email(): void
    {
        $claims = $this->claimsFor(User::factory()->create()->refresh(), 'openid');

        $this->assertArrayNotHasKey('email', $claims);
        $this->assertArrayNotHasKey('email_verified', $claims);
        $this->assertArrayNotHasKey('name', $claims);
    }

    public function test_email_scope_adds_email_and_verification_but_not_name(): void
    {
        $user = User::factory()->create()->refresh();
        $claims = $this->claimsFor($user, 'openid email');

        $this->assertSame($user->email, $claims['email']);
        $this->assertTrue($claims['email_verified']);
        $this->assertArrayNotHasKey('name', $claims);
    }

    public function test_profile_scope_adds_name(): void
    {
        $user = User::factory()->create()->refresh();

        $this->assertSame($user->name, $this->claimsFor($user, 'openid profile')['name']);
    }

    public function test_unverified_email_is_reported_as_unverified(): void
    {
        $user = User::factory()->unverified()->create()->refresh();

        $this->assertFalse($this->claimsFor($user, 'openid email')['email_verified']);
    }
}
