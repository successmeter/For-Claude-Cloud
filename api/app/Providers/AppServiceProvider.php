<?php

namespace App\Providers;

use App\Hub\Identity\FirstPartyClient;
use App\Models\Venue;
use App\Policies\VenuePolicy;
use App\Services\Encryption\KeyManagementService;
use App\Services\Encryption\LocalFileKmsDriver;
use Carbon\CarbonInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Minor #14: no callers construct EnvelopeEncryptor by hand outside tests
        // today, but Plan B/C will need to inject it to encrypt POS credentials, so
        // the interface must resolve via the container. LocalFileKmsDriver is the
        // only implementation that exists in this plan (dev/test-only, see its own
        // environment guard) — swap this binding when a real KMS driver ships.
        $this->app->bind(KeyManagementService::class, LocalFileKmsDriver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Venue::class, VenuePolicy::class);

        $this->configureRateLimiting();
        $this->configureOAuthServer();
    }

    /**
     * The Hub's OAuth 2.0 / OpenID Connect server (Plan B, design §4.1).
     */
    private function configureOAuthServer(): void
    {
        Passport::useClientModel(FirstPartyClient::class);
        Passport::tokensCan(config('openid.passport.tokens_can'));
        // Passport's default access-token lifetime is one year (seen in the Plan B spike).
        Passport::tokensExpireIn(CarbonInterval::minutes(15));
        Passport::refreshTokensExpireIn(CarbonInterval::days(30));
        // Only first-party clients exist and they skip consent (FirstPartyClient), so any
        // request that would need a consent screen is refused.
        Passport::authorizationView(fn () => abort(403, 'Third-party clients are not supported.'));
    }

    /**
     * Named rate limiters for the auth surface (CRITICAL finding #1, final
     * whole-branch review). Without these, /api/login, /api/register, and
     * /api/mfa/confirm are all unlimited: login/register are open to credential
     * stuffing, and mfa/confirm in particular means TOTP is brute-forceable
     * (default ±1 window ≈ 333k possible codes) in minutes at modest request rates.
     */
    private function configureRateLimiting(): void
    {
        // Keyed on email+IP: bounds credential stuffing against a single account
        // without letting one attacker IP exhaust every other account's attempts,
        // and without letting an attacker rotating IPs bypass the limit for a fixed
        // target email.
        RateLimiter::for('login', function (Request $request) {
            $key = Str::lower((string) $request->input('email')).'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });

        // Registration abuse is lower-severity than credential stuffing (no existing
        // account to protect), so this is a simpler per-IP bound.
        RateLimiter::for('register', function (Request $request) {
            return Limit::perHour(10)->by($request->ip());
        });

        // /api/mfa/confirm and /api/mfa/verify both accept a raw TOTP code and are
        // exactly the brute-forceable surface described above. confirm() is behind
        // auth:sanctum, so it's keyed on the authenticated user; verify() (added for
        // CRITICAL finding #2) runs pre-login, so it's keyed on IP+the pending
        // mfa_token instead (no user id is available yet).
        RateLimiter::for('mfa-confirm', function (Request $request) {
            return Limit::perMinute(5)->by((string) $request->user()?->id);
        });

        RateLimiter::for('mfa-verify', function (Request $request) {
            $key = $request->ip().'|'.(string) $request->input('mfa_token');

            return Limit::perMinute(5)->by($key);
        });
    }
}
