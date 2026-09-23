<?php
// api/tests/Feature/Auth/RegistrationTest.php
namespace Tests\Feature\Auth;

use App\Models\Membership;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshesPrivilegedDatabase;

    public function test_registering_creates_an_org_a_user_and_an_owner_membership(): void
    {
        // Note: the brief's literal example password ('correct-horse-battery-staple') is
        // all-lowercase with no digits, which fails the controller's own
        // Password::min(12)->mixedCase()->numbers() rule (see RegisterController) — it
        // would 422 here rather than exercise the success path. Swapped for a password
        // that actually satisfies that rule while keeping the same spirit/length.
        $response = $this->postJson('/api/register', [
            'org_name' => 'Test Cafe Group',
            'name' => 'Jane Owner',
            'email' => 'jane@example.com',
            'password' => 'Correct-Horse-Battery-Staple9',
            'password_confirmation' => 'Correct-Horse-Battery-Staple9',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);
        $membership = Membership::first();
        $this->assertEquals('owner', $membership->role);
    }

    public function test_registration_rejects_a_weak_password(): void
    {
        $response = $this->postJson('/api/register', [
            'org_name' => 'Test Cafe Group',
            'name' => 'Jane Owner',
            'email' => 'jane2@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(422);
    }
}
