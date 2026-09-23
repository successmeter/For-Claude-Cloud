<?php
// api/tests/Feature/Auth/StatefulOriginGatingTest.php
namespace Tests\Feature\Auth;

use App\Models\Membership;
use App\Models\Org;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
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
        // frontend", StartSession never ran, so $request->hasSession() is false.
        // Minor finding #13 (final whole-branch review): this used to fall through to
        // an unconditional $request->session()->regenerate() call, throwing
        // `RuntimeException: Session store not set on request.` and surfacing as an
        // unhandled 500 -- this test used to assert that 500 directly, codifying a bug
        // as expected behavior. LoginController::establishSession() now checks
        // hasSession() first (matching the pattern RegisterController already used)
        // and returns a clean 400 instead. The pre-fix vulnerability was a *silent*
        // cross-site login (attacker's form -> a real 200 + a real session cookie in
        // the victim's browser); this is neither — it is a clean, intentional 4xx with
        // zero cookies, which is what closes the CSRF login vector. (A
        // stateless-but-successful bearer-token style flow is out of scope for this
        // task; see task-5-report.md.)
        $response->assertStatus(400);
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
        // See RegistrationTest::fakeUncompromisedPasswordCheck() -- MINOR finding #18
        // (final whole-branch review) added Password::uncompromised() to
        // RegisterController, which calls the live Have I Been Pwned API; faking it
        // keeps this test deterministic and network-independent.
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);

        // Captured via the Org "created" model event -- see
        // RegistrationTest::test_registering_creates_an_org_a_user_and_an_owner_membership
        // for why this (rather than a separate-connection query) is needed once
        // RegisterController clears tenant context at the end of the request.
        $capturedOrgId = null;
        Org::created(function (Org $org) use (&$capturedOrgId) {
            $capturedOrgId = $org->id;
        });

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
        //
        // IMPORTANT finding #12 (final whole-branch review): RegisterController now
        // calls TenantContext::clear() at the end of the request, and this test's
        // assertions run against the same RLS-scoped app_user connection the request
        // itself used -- so reading back the org row (and the org-scoped membership
        // row) requires re-establishing tenant context for the org just created,
        // exactly as a real *subsequent* request for that org would (via
        // SetTenantContext; Plan A doesn't yet wire real per-request org resolution
        // beyond the local/testing X-Org-Id header -- that's Plan B). A query-based
        // lookup on a separate ('pgsql') connection doesn't work here: RefreshDatabase
        // wraps this test in an uncommitted transaction on the default (pgsql_app)
        // connection, invisible to a different session under READ COMMITTED
        // isolation -- hence the event-based capture above instead.
        $this->assertNotNull($capturedOrgId, 'Registration did not create an org.');
        TenantContext::set($capturedOrgId);

        $this->assertDatabaseHas('orgs', ['name' => 'Curl Cafe']);
        $this->assertDatabaseHas('users', ['email' => 'casey@example.com']);
        $membership = Membership::whereHas('user', fn ($q) => $q->where('email', 'casey@example.com'))->first();
        $this->assertNotNull($membership);
        $this->assertEquals('owner', $membership->role);

        // No session was started for this request at all, so nothing is authenticated.
        $this->assertGuest();
    }
}
