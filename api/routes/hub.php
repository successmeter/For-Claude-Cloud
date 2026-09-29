<?php
// api/routes/hub.php
//
// Hub contract v1 (Plan B; schemas in api/contract/v1). Loaded from bootstrap/app.php with no
// middleware group: callers are other tools' backends with bearer tokens, not browsers, so there
// is no session, cookie or CSRF here.
//
// Middleware, in the order the priority list enforces:
//   auth.hub:<scopes>  bearer token -> HubCaller (user and/or tool), scope check
//   hub.user           refuse tool-only (client-credentials) tokens
//   tenant             X-Hub-Org -> membership (or tool link) -> TenantContext::run()
//   mfa.owner          owners must have MFA

use App\Hub\Http\Controllers\CompetitorSetController;
use App\Hub\Http\Controllers\CompetitorSetWriteController;
use App\Hub\Http\Controllers\MeController;
use App\Hub\Http\Controllers\OrgController;
use App\Hub\Http\Controllers\ToolLinkController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

Route::prefix('hub/v1')->middleware(SubstituteBindings::class)->group(function () {
    Route::get('/me', [MeController::class, 'show'])->middleware(['auth.hub:openid', 'hub.user']);
    Route::get('/me/orgs', [MeController::class, 'orgs'])->middleware(['auth.hub:orgs', 'hub.user']);

    Route::get('/orgs/{org}', [OrgController::class, 'show'])->middleware(['auth.hub:orgs', 'tenant']);
    Route::put('/orgs/{org}/tools/{tool}', [ToolLinkController::class, 'update'])
        ->middleware(['auth.hub', 'hub.user', 'tenant', 'mfa.owner']);

    // Competitor sets (design §4.5). Reads: users and linked tools.
    Route::middleware(['auth.hub:competitor-sets:read', 'tenant'])->group(function () {
        Route::get('/orgs/{org}/competitor-sets', [CompetitorSetController::class, 'index']);
        Route::get('/orgs/{org}/competitor-sets/{set}', [CompetitorSetController::class, 'show']);
        Route::post('/orgs/{org}/competitor-sets/{set}/activation', [CompetitorSetController::class, 'activate']);
    });

    // Writes: people only (a tool token never holds competitor-sets:write, and hub.user refuses it).
    Route::middleware(['auth.hub:competitor-sets:write', 'hub.user', 'tenant'])->group(function () {
        Route::post('/orgs/{org}/competitor-sets', [CompetitorSetWriteController::class, 'store']);
        Route::patch('/orgs/{org}/competitor-sets/{set}', [CompetitorSetWriteController::class, 'update']);
        Route::delete('/orgs/{org}/competitor-sets/{set}', [CompetitorSetWriteController::class, 'destroy'])->middleware('mfa.owner');
        Route::post('/orgs/{org}/competitor-sets/{set}/members', [CompetitorSetWriteController::class, 'storeMember']);
        Route::patch('/orgs/{org}/competitor-sets/{set}/members/{member}', [CompetitorSetWriteController::class, 'updateMember']);
        Route::delete('/orgs/{org}/competitor-sets/{set}/members/{member}', [CompetitorSetWriteController::class, 'destroyMember']);
    });
});
