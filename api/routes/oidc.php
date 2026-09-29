<?php
// api/routes/oidc.php
//
// OIDC endpoints the Hub adds on top of Passport and the OIDC package (Plan B Task 9). Loaded from
// bootstrap/app.php with no middleware group, so each route states exactly what it needs. The
// route names are the ones the package's discovery document looks for.

use App\Hub\Http\Controllers\EndSessionController;
use App\Hub\Http\Controllers\UserInfoController;
use Illuminate\Support\Facades\Route;

Route::get('/oauth/userinfo', UserInfoController::class)
    ->middleware('auth:api')
    ->name('openid.userinfo');

// Needs the browser's Hub session to end it, hence the web group.
Route::get('/oauth/logout', EndSessionController::class)
    ->middleware('web')
    ->name('openid.end_session_endpoint');
