<?php
// api/tests/Feature/Auth/LoginTest.php
namespace Tests\Feature\Auth;

use App\Models\User;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshesPrivilegedDatabase;

    public function test_a_registered_user_can_log_in(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-horse-battery-staple')]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-staple',
        ]);

        $response->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_a_wrong_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-horse-battery-staple')]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $this->assertGuest();
    }
}
