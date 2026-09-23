<?php
// api/routes/api.php
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\MfaController;
use App\Http\Controllers\Auth\RegisterController;
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

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/mfa/enroll', [MfaController::class, 'enroll']);
    Route::post('/mfa/confirm', [MfaController::class, 'confirm'])->middleware('throttle:mfa-confirm');
});
