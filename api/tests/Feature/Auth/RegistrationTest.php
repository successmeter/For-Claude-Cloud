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
        //
        // The Origin header is required since bootstrap/app.php now gates the 'api'
        // middleware group's session/cookie/CSRF stack behind Sanctum's
        // EnsureFrontendRequestsAreStateful — only requests whose Origin/Referer matches
        // a domain in config('sanctum.stateful') get a session at all (see that config
        // file's defaults). 'http://localhost' matches the 'localhost' entry there.
        // Without this header, the request is treated as non-frontend/stateless, and
        // auth()->login() + $request->session()->regenerate() in RegisterController
        // would throw because no session was ever started for the request.
        $response = $this->postJson('/api/register', [
            'org_name' => 'Test Cafe Group',
            'name' => 'Jane Owner',
            'email' => 'jane@example.com',
            'password' => 'Correct-Horse-Battery-Staple9',
            'password_confirmation' => 'Correct-Horse-Battery-Staple9',
        ], ['Origin' => 'http://localhost']);

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
        ], ['Origin' => 'http://localhost']);

        $response->assertStatus(422);
    }

    public function test_registering_regenerates_the_session_id_to_prevent_session_fixation(): void
    {
        $cookieName = config('session.cookie');

        // Simulate an attacker "fixing" a session id in the victim's browser before
        // registration: make a stateful request that starts a session (validation
        // failure here is irrelevant — StartSession has already run and assigned an
        // id by the time the controller's validate() call rejects the input), then
        // read the *actual* session cookie the server sent back (decrypted, the same
        // way a real browser's cookie jar would hold it) rather than inspecting
        // container internals, which don't reliably reflect a specific request/response
        // cycle.
        //
        // Note: withCredentials() is required for postJson() to send any cookies at
        // all — prepareCookiesForJsonRequest() returns an empty array otherwise, so
        // without it withCookie()/withCookies() are silently no-ops for JSON requests.
        $priorResponse = $this->withCredentials()->postJson('/api/register', [
            'org_name' => 'Unrelated Org',
            'name' => 'Nobody',
            'email' => 'not-a-real-registration@example.com',
            'password' => 'weak',
        ], ['Origin' => 'http://localhost']);

        $fixedSessionId = $this->decryptCookieValue($priorResponse, $cookieName);
        $this->assertNotEmpty($fixedSessionId);

        // Now register for real while presenting that same (attacker-known) session id
        // as an incoming cookie, exactly as a victim's browser would if the attacker
        // had planted it beforehand.
        $response = $this->withCredentials()
            ->withCookie($cookieName, $fixedSessionId)
            ->postJson('/api/register', [
                'org_name' => 'Test Cafe Group 2',
                'name' => 'Jane Owner',
                'email' => 'jane-fixation@example.com',
                'password' => 'Correct-Horse-Battery-Staple9',
                'password_confirmation' => 'Correct-Horse-Battery-Staple9',
            ], ['Origin' => 'http://localhost']);

        $response->assertCreated();

        // The post-auth session id must never equal the pre-auth id an attacker could
        // have fixed — otherwise the attacker's known session id would now be
        // authenticated as the victim, and the attacker could use it directly.
        //
        // Investigation note: Illuminate\Auth\SessionGuard::login() -> updateSession()
        // already calls $this->session->regenerate(true) internally on every login, so
        // this property held even before RegisterController's explicit
        // $request->session()->regenerate() call was added (confirmed by temporarily
        // removing that line and re-running this test: it still passed). The explicit
        // call is still correct to add — it makes the intent self-documenting at the
        // controller level, matches LoginController::login()'s existing pattern exactly
        // as requested, and doesn't rely on call-site knowledge of SessionGuard's
        // internals holding across future refactors (e.g. a custom guard).
        $newSessionId = $this->decryptCookieValue($response, $cookieName);
        $this->assertNotEmpty($newSessionId);
        $this->assertNotEquals($fixedSessionId, $newSessionId);
    }

    /**
     * Decrypt a session/XSRF-style cookie from a test response the same way
     * EncryptCookies would decrypt it on a real incoming request, so assertions
     * compare the actual plaintext session id rather than the (always-different,
     * randomly-IV'd) encrypted cookie value.
     */
    private function decryptCookieValue($response, string $name): ?string
    {
        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === $name);

        if (! $cookie) {
            return null;
        }

        $decrypted = app('encrypter')->decrypt($cookie->getValue(), false);

        return \Illuminate\Cookie\CookieValuePrefix::remove($decrypted);
    }
}
