<?php
// api/tests/Feature/Auth/MeAndRegistrationTest.php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\SpaRequests;
use Tests\TestCase;

/** Plan D Task 1: who am I, registration switch, public ids at login. */
class MeAndRegistrationTest extends TestCase
{
    use RefreshesPrivilegedDatabase, SpaRequests;

    public function test_me_lists_the_users_orgs_and_roles(): void
    {
        $user = User::factory()->create(['name' => 'Sam', 'email' => 'sam@example.com'])->refresh();
        $a = $this->org('Zeta Cafe');
        $b = $this->org('Alpha Bar');
        foreach ([[$a, 'owner'], [$b, 'viewer']] as [$org, $role]) {
            \App\Services\Tenancy\TenantContext::run($org->id, fn () => \App\Models\Membership::create(['org_id' => $org->id, 'user_id' => $user->id, 'role' => $role]));
        }
        $this->org('Not mine');

        $response = $this->actingAs($user)->getJson('/api/me')->assertOk();

        $response->assertExactJson([
            'user' => ['id' => $user->public_id, 'name' => 'Sam', 'email' => 'sam@example.com', 'mfa_enabled' => false],
            'orgs' => [
                ['id' => $b->id, 'name' => 'Alpha Bar', 'role' => 'viewer'],
                ['id' => $a->id, 'name' => 'Zeta Cafe', 'role' => 'owner'],
            ],
        ]);
    }

    public function test_me_with_no_orgs(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/me')->assertOk()->assertJsonPath('orgs', []);
    }

    public function test_me_needs_sign_in(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_registration_can_be_switched_off(): void
    {
        config(['hub.open_registration' => false]);
        Http::fake();

        $this->postJson('/api/register', [
            'org_name' => 'X', 'name' => 'Y', 'email' => 'y@example.com',
            'password' => 'Correct-Horse-9-Battery', 'password_confirmation' => 'Correct-Horse-9-Battery',
        ])->assertNotFound()->assertJsonPath('type', 'https://hub/problems/registration_closed');

        $this->assertSame(0, User::count());
    }

    public function test_registration_is_off_unless_configured(): void
    {
        // Tests switch it on (phpunit.xml); production leaves HUB_OPEN_REGISTRATION unset.
        $this->assertStringContainsString("env('HUB_OPEN_REGISTRATION', false)", file_get_contents(config_path('hub.php')));
    }

    public function test_login_answers_the_public_id(): void
    {
        $user = User::factory()->create(['password' => 'Correct-Horse-9-Battery'])->refresh();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Correct-Horse-9-Battery'], ['Origin' => 'http://localhost'])
            ->assertOk()->assertExactJson(['id' => $user->public_id]);
    }
}
