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

        // The Origin header is required since bootstrap/app.php now gates the 'api'
        // middleware group's session/cookie/CSRF stack behind Sanctum's
        // EnsureFrontendRequestsAreStateful — only requests whose Origin/Referer matches
        // a domain in config('sanctum.stateful') get a session at all. 'http://localhost'
        // matches the 'localhost' entry there. This is also what closes the login-CSRF
        // vulnerability: a cross-site attacker's form POST won't carry this Origin/Referer,
        // so it won't be treated as a stateful/frontend request at all (see
        // StatefulOriginGatingTest for a direct proof of that).
        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-staple',
        ], ['Origin' => 'http://localhost']);

        $response->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_a_wrong_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-horse-battery-staple')]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ], ['Origin' => 'http://localhost']);

        $response->assertStatus(422);
        $this->assertGuest();
    }
}
