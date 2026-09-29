<?php

use App\Hub\Http\Controllers\WebLoginController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Hosted login for the Hub's OIDC authorize endpoint (Plan B Task 8). The route name `login` is
// where /oauth/authorize sends unauthenticated browsers.
Route::middleware('guest')->group(function () {
    Route::get('/login', [WebLoginController::class, 'show'])->name('login');
    Route::post('/login', [WebLoginController::class, 'login'])->middleware('throttle:login');
    Route::get('/login/mfa', [WebLoginController::class, 'showMfa'])->name('login.mfa');
    Route::post('/login/mfa', [WebLoginController::class, 'verifyMfa'])->middleware('throttle:web-mfa');
});

Route::post('/logout', [WebLoginController::class, 'logout'])->middleware('auth')->name('logout');
