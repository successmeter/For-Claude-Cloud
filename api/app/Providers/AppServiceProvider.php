<?php

namespace App\Providers;

use App\Hub\Http\Middleware\RequireS256Pkce;
use App\Hub\Identity\FirstPartyClient;
use App\Hub\Identity\ReuseDetectingRefreshTokenRepository;
use App\Models\Venue;
use App\Policies\VenuePolicy;
use App\Ingest\Scanning\ClamAvUploadScanner;
use App\Ingest\Scanning\NullUploadScanner;
use App\Ingest\Scanning\SocketClamdTransport;
use App\Ingest\Scanning\UploadScanner;
use App\Services\Encryption\AwsKmsDriver;
use App\Services\Encryption\KeyManagementService;
use App\Services\Encryption\LocalFileKmsDriver;
use Carbon\CarbonInterval;
use Aws\Kms\KmsClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
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
        // Credentials handed to other services (hub:client / hub:webhook-endpoint --aws-secret).
        $this->app->bind(\App\Services\Secrets\SecretsWriter::class, fn () => new \App\Services\Secrets\AwsSecretsWriter(
            new \Aws\SecretsManager\SecretsManagerClient(['region' => config('kms.aws_region'), 'version' => 'latest'])
        ));

        // Minor #14: no callers construct EnvelopeEncryptor by hand outside tests
        // today, but Plan B/C will need to inject it to encrypt POS credentials, so
        // the interface must resolve via the container. LocalFileKmsDriver is the
        // only implementation that exists in this plan (dev/test-only, see its own
        // environment guard) — swap this binding when a real KMS driver ships.
        $this->app->singleton(KeyManagementService::class, fn () => config('kms.driver') === 'aws'
            ? new AwsKmsDriver(new KmsClient(['region' => config('kms.aws_region'), 'version' => '2014-11-01']), (string) config('kms.aws_key_id'))
            : new LocalFileKmsDriver);

        // Upload malware scanning (Plan C design §4.1).
        $this->app->bind(UploadScanner::class, fn ($app) => config('ingest.scanner') === 'none'
            ? new NullUploadScanner($app->environment())
            : new ClamAvUploadScanner(new SocketClamdTransport(config('ingest.clamd.address'), config('ingest.clamd.timeout'))));

        // Revoke the whole token family when a rotated refresh token is replayed (Plan B Task 10).
        $this->app->bind(\Laravel\Passport\Bridge\RefreshTokenRepository::class, ReuseDetectingRefreshTokenRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Reset links open the Revenue app, which posts to /api/password/reset.
        \Illuminate\Auth\Notifications\ResetPassword::createUrlUsing(fn ($user, string $token) => config('app.frontend_url')
            .'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->getEmailForPasswordReset()]));

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

        // Passport registers its routes while its own provider boots, after this one, so the
        // authorize route only exists once the application has booted.
        $this->app->booted(function () {
            Route::getRoutes()->refreshNameLookups();
            Route::getRoutes()->getByName('passport.authorizations.authorize')
                ?->middleware(RequireS256Pkce::class);
        });
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
        // Sales uploads (inspect and upload share it): 20 files per user per hour.
        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perHour(20)->by('uploads|'.(string) $request->user()?->id);
        });

        // Forgotten passwords: per address and per IP, so neither one person's inbox nor the reset
        // form can be hammered.
        RateLimiter::for('password-reset', function (Request $request) {
            return [
                Limit::perMinute(5)->by('password-reset|'.$request->ip()),
                Limit::perHour(10)->by('password-reset|'.Str::lower((string) $request->input('email'))),
            ];
        });

        // Invitation links (Plan D): anyone holding one may look it up or accept it.
        RateLimiter::for('invitations', function (Request $request) {
            return Limit::perMinute(10)->by('invitations|'.$request->ip());
        });

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

        // The hosted OIDC login's TOTP step (Plan B Task 8). There is no mfa_token in that
        // flow -- the pending user is held in the session -- so key on that user, which also
        // caps an attacker who spreads guesses for one account across many IPs.
        RateLimiter::for('web-mfa', function (Request $request) {
            $pending = $request->hasSession() ? (string) $request->session()->get('login.pending_user_id') : '';

            return Limit::perMinute(5)->by('web-mfa|'.$pending.'|'.($pending === '' ? $request->ip() : ''));
        });
    }
}
