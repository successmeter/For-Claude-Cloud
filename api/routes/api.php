<?php
// api/routes/api.php
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\MfaController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\Uploads\InspectUploadController;
use App\Http\Controllers\Uploads\TemplateController;
use App\Http\Controllers\Uploads\UploadController;
use App\Http\Controllers\Venues\InsightsController;
use App\Http\Controllers\Venues\MetricsController;
use App\Http\Controllers\Venues\OverviewController;
use App\Http\Controllers\Venues\VenueController;
use Illuminate\Support\Facades\Route;

// CRITICAL finding #1 (final whole-branch review): named limiters (registered in
// AppServiceProvider::configureRateLimiting()) applied to the auth surface. Without
// these, /api/login, /api/register, and /api/mfa/confirm were all unlimited --
// /api/mfa/confirm in particular meant TOTP was brute-forceable (default ±1 window
// ≈ 333k possible codes) in minutes at modest request rates.
Route::post('/register', RegisterController::class)->middleware('throttle:register');
Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:login');
Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth:sanctum');

// CRITICAL finding #2: guest-accessible (the caller isn't authenticated yet) --
// completes login for a user whose password already checked out but who has MFA
// enabled. See MfaController::verify().
Route::post('/mfa/verify', [MfaController::class, 'verify'])->middleware('throttle:mfa-verify');

// Invitations (Plan D): the link is the credential, so these are guest routes. Accepting signs in.
Route::middleware('throttle:invitations')->group(function () {
    Route::get('/invitations/{token}', [InvitationController::class, 'show']);
    Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept']);
});

Route::middleware('auth:sanctum')->group(function () {
    // Who am I, and which orgs (Plan D): no org header needed.
    Route::get('/me', MeController::class);
    // Follow-up finding #3 (post-merge review): enroll()'s re-enrollment branch
    // requires a valid current TOTP code (see MfaController::enroll()), which is
    // exactly the brute-forceable-TOTP surface CRITICAL finding #1 closed for
    // /api/mfa/confirm and /api/mfa/verify -- this route had no throttle at all,
    // reopening that same class of attack on the path the re-enrollment fix itself
    // created. Reuses the existing 'mfa-confirm' limiter (5/min by authenticated
    // user id): same threat model as confirm().
    Route::post('/mfa/enroll', [MfaController::class, 'enroll'])->middleware('throttle:mfa-confirm');
    Route::post('/mfa/confirm', [MfaController::class, 'confirm'])->middleware('throttle:mfa-confirm');
});

// The upload template is a plain download (no org header), so it sits outside the tenant group.
Route::get('/uploads/template.csv', TemplateController::class)->middleware('auth:sanctum');

// First-party app API (Plan C). The React app sends X-Hub-Org like every other caller (Plan B);
// `tenant` resolves the caller's role in that org and runs the request in its tenant context.
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('/venues', [VenueController::class, 'index']);
    Route::post('/venues', [VenueController::class, 'store']);
    Route::get('/venues/{venue}', [VenueController::class, 'show']);
    Route::patch('/venues/{venue}', [VenueController::class, 'update']);
    Route::get('/venues/{venue}/overview', OverviewController::class);
    Route::get('/venues/{venue}/metrics', MetricsController::class);
    Route::get('/venues/{venue}/insights/latest', [InsightsController::class, 'latest']);

    // Sales uploads (Plan C design §4-5): owners and managers; 20 files per user per hour.
    Route::middleware('throttle:uploads')->group(function () {
        Route::post('/venues/{venue}/uploads/inspect', InspectUploadController::class);
        Route::post('/venues/{venue}/uploads', [UploadController::class, 'store']);
    });
    Route::get('/venues/{venue}/uploads', [UploadController::class, 'index']);
    Route::get('/uploads/{run}', [UploadController::class, 'show']);
    Route::get('/uploads/{run}/changes', [UploadController::class, 'changes']);
    Route::post('/uploads/{run}/commit', [UploadController::class, 'commit']);
    Route::delete('/uploads/{run}', [UploadController::class, 'destroy']);

    // Settings -> Team (Plan D): owners and managers read; owner writes need MFA.
    Route::get('/team', [TeamController::class, 'index']);
    Route::middleware('mfa.owner')->group(function () {
        Route::post('/team/invitations', [TeamController::class, 'invite']);
        Route::delete('/team/invitations/{invitation}', [TeamController::class, 'revoke']);
        Route::patch('/team/members/{user}', [TeamController::class, 'updateMember']);
        Route::delete('/team/members/{user}', [TeamController::class, 'removeMember']);
    });
});
