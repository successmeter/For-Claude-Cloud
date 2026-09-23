<?php
// api/tests/Feature/Auth/StatefulOriginGatingTest.php
namespace Tests\Feature\Auth;

use App\Models\Membership;
use App\Models\User;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\TestCase;

/**
 * Direct proof that bootstrap/app.php's swap from unconditionally appending
 * EncryptCookies/AddQueuedCookiesToResponse/StartSession to the 'api' middleware
 * group, over to Sanctum's EnsureFrontendRequestsAreStateful, actually closes the
 * login-CSRF vulnerability: a request whose Origin does NOT match
 * config('sanctum.stateful') must never receive a session cookie, regardless of
 * whether the credentials it presents are valid.
 */
class StatefulOriginGatingTest extends TestCase
{
    use RefreshesPrivilegedDatabase;

    public function test_a_login_request_from_a_non_stateful_origin_receives_no_session_cookie(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-horse-battery-staple')]);

        // 'https://evil.example.com' is not in config('sanctum.stateful') (which only
        // contains the Sanctum install's localhost-variant defaults), so
        // EnsureFrontendRequestsAreStateful::fromFrontend() returns false and none of
        // EncryptCookies/AddQueuedCookiesToResponse/StartSession/CSRF verification run
        // for this request at all — simulating exactly the attacker-controlled
        // cross-site auto-submitting form described in the vulnerability report.
        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-staple',
        ], ['Origin' => 'https://evil.example.com']);

        // No cookie of any kind — session, XSRF-TOKEN, or otherwise — is set, because
        // the entire cookie-queueing middleware stack was skipped for this request.
        $response->assertHeaderMissing('set-cookie');

        // Empirically, this fails safe rather than silently succeeding: since
        // EnsureFrontendRequestsAreStateful decided this request is not "from the
        // frontend", StartSession never ran, so LoginController's own
        // $request->session()->regenerate() call throws
        // `RuntimeException: Session store not set on request.`, surfaced as a 500. The
        // pre-fix vulnerability was a *silent* cross-site login (attacker's form -> a
        // real 200 + a real session cookie in the victim's browser); this is neither —
        // it is a hard failure with zero cookies, which is what closes the CSRF login
        // vector. (A stateless-but-successful bearer-token style flow is out of scope
        // for this task; see task-5-report.md.)
        $response->assertStatus(500);
    }

    public function test_a_login_request_from_a_stateful_origin_receives_a_session_cookie(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-horse-battery-staple')]);

        // 'http://localhost' matches the 'localhost' entry in config('sanctum.stateful')
        // (Sanctum's install default), so this is treated as a genuine first-party
        // frontend request and gets the full session/cookie/CSRF stack — this is the
        // control case proving the gating is origin-based, not simply broken outright.
        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-staple',
        ], ['Origin' => 'http://localhost']);

        $response->assertOk();
        $response->assertHeader('set-cookie');
        $this->assertAuthenticatedAs($user);
    }

    /**
     * Round 2 finding: registration must stay callable by non-browser clients
     * (curl, a mobile client, a future integration) -- unlike login, it is not
     * restricted to the SPA's stateful origin. Round 1's gating means a request
     * from a non-stateful origin never gets StartSession run on it, so
     * RegisterController must not unconditionally touch $request->session():
     * before this fix, the Org/User/Membership rows were committed successfully
     * and *then* the request 500'd trying to call auth()->login() / regenerate()
     * against a session that was never started. That's misleading (the write
     * succeeded despite the error response) and risks confused-retry duplicate
     * signups. This proves the account is created cleanly with a 201 and no
     * session/cookie at all from a non-stateful origin.
     */
    public function test_a_registration_request_from_a_non_stateful_origin_succeeds_without_a_session(): void
    {
        $response = $this->postJson('/api/register', [
            'org_name' => 'Curl Cafe',
            'name' => 'Casey Caller',
            'email' => 'casey@example.com',
            'password' => 'Correct-Horse-Battery-Staple9',
            'password_confirmation' => 'Correct-Horse-Battery-Staple9',
        ], ['Origin' => 'https://evil.example.com']);

        $response->assertCreated();
        $response->assertHeaderMissing('set-cookie');

        // The account must be genuinely, fully persisted -- not a partial or rolled
        // back write -- even though no session could be (or was) established.
        $this->assertDatabaseHas('orgs', ['name' => 'Curl Cafe']);
        $this->assertDatabaseHas('users', ['email' => 'casey@example.com']);
        $membership = Membership::whereHas('user', fn ($q) => $q->where('email', 'casey@example.com'))->first();
        $this->assertNotNull($membership);
        $this->assertEquals('owner', $membership->role);

        // No session was started for this request at all, so nothing is authenticated.
        $this->assertGuest();
    }
}
