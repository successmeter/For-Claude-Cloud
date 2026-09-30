<?php
// api/tests/Feature/Auth/AppCookieSessionTest.php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\TestCase;

/**
 * Plan D Task 7: the Revenue app's cookie session, end to end. The app is served from its own host
 * and forwards /api and /sanctum here, so the browser's Origin is the app's host.
 */
class AppCookieSessionTest extends TestCase
{
    use RefreshesPrivilegedDatabase;

    private const PASSWORD = 'Correct-Horse-9-Battery';

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['app.example.test']]);
    }

    /** Cookies from a response, as the browser would send them back (still encrypted). */
    private function cookiesFrom(TestResponse $response): array
    {
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $cookies;
    }

    public function test_csrf_cookie_then_login_then_me_from_the_app(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD])->refresh();
        $origin = ['Origin' => 'https://app.example.test', 'Referer' => 'https://app.example.test/signin'];

        $csrf = $this->get('/sanctum/csrf-cookie', $origin)->assertNoContent();
        $cookies = $this->cookiesFrom($csrf);
        $this->assertArrayHasKey('XSRF-TOKEN', $cookies);
        $session = collect($csrf->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));
        $this->assertTrue($session->isHttpOnly());
        $this->assertSame('lax', $session->getSameSite());
        $this->assertNull($session->getDomain(), 'host-only: the cookie belongs to the app host');
        $this->assertFalse(collect($csrf->headers->getCookies())->first(fn ($c) => $c->getName() === 'XSRF-TOKEN')->isHttpOnly(), 'the app reads XSRF-TOKEN');

        $login = $this->withUnencryptedCookies($cookies)
            ->postJson('/api/login', ['email' => $user->email, 'password' => self::PASSWORD], $origin + ['X-XSRF-TOKEN' => urldecode($cookies['XSRF-TOKEN'])])
            ->assertOk()->assertExactJson(['id' => $user->public_id]);

        $this->withUnencryptedCookies($this->cookiesFrom($login) + $cookies)
            ->getJson('/api/me', $origin)->assertOk()->assertJsonPath('user.id', $user->public_id);
    }

    public function test_another_origin_gets_no_session(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $evil = ['Origin' => 'https://evil.example.test'];

        $this->postJson('/api/login', ['email' => $user->email, 'password' => self::PASSWORD], $evil)
            ->assertStatus(400)->assertHeaderMissing('set-cookie');
    }

    public function test_state_changing_app_requests_are_csrf_checked(): void
    {
        // Laravel skips the CSRF check inside tests, so check the wiring: the stateful API stack
        // Sanctum adds for the app's origin includes the CSRF middleware.
        $this->assertSame(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, config('sanctum.middleware.validate_csrf_token'));
        $this->assertStringContainsString('localhost:5173,', file_get_contents(config_path('sanctum.php')), 'the Vite dev server is stateful by default');
    }
}
