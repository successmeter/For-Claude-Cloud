# Hub Contract v1 and Shared Competitor Sets (Plan B of 4) Implementation Plan

> **For agentic workers:** Execute task by task, in order within a track. Steps use checkbox (`- [ ]`) syntax for tracking.
> Every task is test-first: write the test, see it fail for the stated reason, implement, see it pass, run the whole suite, commit.

**Goal:** Make the Revenue tool's Hub an OpenID Connect provider with a versioned contract (REST v1, JSON Schemas, signed
webhooks), own competitor sets shared with the Web Performance tool, and give the Web tool real authentication and
Hub-backed competitor sets.

**Spec:** `plans/2026-09-29-plan-b-hub-contract-design.md` (decisions B1-B9). Also `03-integrations.md` §3.3,
`05-security.md` §5.3-5.4, and Plan A's Open Items 2-4.

**Two repositories, two tracks:**

| Track | Repo | Branch | Tasks |
|---|---|---|---|
| Hub (H0-H4) | `successmeter/For-Claude-Cloud`, app in `api/` | this repo's working branch | 1-19 |
| Web (W1-W2) | `successmeter/traffic-dashboard` | a new feature branch in that repo (get the owner's go-ahead before pushing there) | 20-27 |

Web tasks 20-23 need only the fake Hub, so they can start in parallel with H1. Tasks 24-26 need the Hub's H3/H4
contract to be merged (schemas are the handshake).

## Verified before writing this plan (spike, 2026-09-29)

A throwaway copy of `api/` was wired with the packages below and run against Postgres 16:

- Foundation baseline: **48/48 tests pass**.
- `laravel/passport` **v13.8.0** and `jeremy379/laravel-openid-connect` **v3.3.0** install on Laravel **13.33** / PHP 8.4
  (`league/oauth2-server` 9.4.1, `lcobucci/jwt` 5.6.0). The package README only claims Laravel 12; it works on 13.
- PHPUnit: authorization code + PKCE (S256) -> `id_token` with `sub = users.public_id`, `nonce` echoed; discovery and JWKS
  served; client-credentials grant works; a reused refresh token is rejected (400). **49/49 pass** with the spike test.
- Node 22 + `openid-client` **6.8.8** against the running spike Hub (`artisan serve`): discovery, code exchange with full
  id_token validation (issuer, RS256 signature via JWKS, audience, nonce), refresh and client-credentials all succeed.

Findings that shaped the tasks:

1. The package's `/oauth/userinfo` returns `sub` = the **bigint `users.id`** (it uses the access-token subject). Task 9
   replaces it with our own controller.
2. Passport's access-token JWT `sub` is always `users.id` (the TokenGuard resolves users by it). Contract rule: **clients
   treat access tokens as opaque** and use only `id_token.sub` / `/oauth/userinfo.sub` / `/hub/v1/me.sub` as the user id.
3. Passport's default access-token lifetime is **1 year** (`expires_in: 31536000`). Task 6 sets 15 minutes explicitly.
4. The package emits `iat`/`exp` with microseconds unless `use_microseconds` is false. Task 6 turns it off.
5. Discovery advertises PKCE `plain`; Task 8 rejects anything but `S256` at `/oauth/authorize`.
6. Passport 13 no longer registers the JSON client-management routes by default (`Passport::$registersJsonApiRoutes`),
   so no end user can create OAuth clients. Keep it that way.
7. `amr`/`auth_time` claims would need the browser session at the token endpoint, which is a back-channel call.
   **Dropped from Plan B** (design open item); the Hub enforces MFA itself at login, so relying parties do not need them.

## Global Constraints

- Everything in Plan A's Global Constraints still holds (RLS on every tenant table, non-superuser `app_user`, owner /
  manager / viewer roles, append-only audit log, envelope encryption only where specified).
- Tenant context is **transaction-local** from Task 1 onward. No code may call `set_config(..., false)`.
- Every new table with an `org_id` column gets `ENABLE` + `FORCE ROW LEVEL SECURITY` and a policy, unless it is on the
  catalog test's allowlist with a written reason (Task 4).
- `app_user` never gets `DELETE` by default. Grant it per table, with a comment saying why cascades cannot cross orgs.
- The user id exposed outside the Hub is `users.public_id` (UUID). Never the bigint `users.id`.
- Contract payloads must validate against `api/contract/v1/*.schema.json`. Changing a schema in a way that breaks an
  existing consumer requires `/hub/v2`.
- Webhook bodies carry ids only, never set names, member names or any other tenant content.
- Web tool: no request may choose its tenant through input. The tenant always comes from the server-side session.
- No real secrets in either repo. Dev keys are generated locally and gitignored.

---

## File Structure

```
api/
  app/Hub/
    Identity/FirstPartyClient.php           Passport client model: first-party clients skip consent
    Identity/HubIdentityRepository.php      id_token identity: sub = public_id
    Identity/HubIdentityEntity.php          id_token claims
    Identity/ReuseDetectingRefreshTokenRepository.php
    Http/Controllers/WebLoginController.php hosted login + MFA pages for /oauth/authorize
    Http/Controllers/UserInfoController.php
    Http/Controllers/EndSessionController.php
    Http/Controllers/MeController.php
    Http/Controllers/OrgController.php
    Http/Controllers/ToolLinkController.php
    Http/Controllers/CompetitorSetController.php
    Http/Controllers/CompetitorSetMemberController.php
    Http/Controllers/ActivationController.php
    Http/Middleware/AuthenticateHubCaller.php   bearer token -> HubCaller (user and/or tool)
    Http/Middleware/RequireUserCaller.php
    Http/Middleware/RequireS256Pkce.php
    Http/HubCaller.php
    Http/Problem.php                        RFC 9457 responses
    Models/OrgToolLink.php, CompetitorSet.php, CompetitorSetMember.php, GeoArea.php,
           WebhookEndpoint.php, WebhookOutboxEntry.php
    Services/CompetitorSetService.php       all set writes, lock rules, outbox writes
    Events/HubEvents.php                    outbox writer
    Webhooks/WebhookSigner.php
    Jobs/DeliverWebhook.php
  app/Http/Middleware/ResolveTenant.php     replaces SetTenantContext
  app/Http/Middleware/EnsureOwnerHasMfa.php
  app/Jobs/Middleware/RunsWithTenantContext.php
  app/Jobs/Contracts/TenantScopedJob.php
  app/Services/Tenancy/TenantContext.php    (rewritten: transaction-local)
  config/openid.php                         (published, edited)
  contract/v1/*.schema.json
  resources/views/auth/login.blade.php, auth/mfa.blade.php
  routes/hub.php                            /hub/v1/*
  routes/web.php                            /login, /login/mfa, /oauth/userinfo, /oauth/logout
  database/migrations/2026_10_*             (one per task, listed per task)
  tests/Feature/Hub/*.php, tests/Feature/Tenancy/*.php, tests/Support/HubTokens.php

traffic-dashboard/
  server/app.js            createApp({ pool, hub, ga }) : Express app (extracted from index.js)
  server/index.js          boots createApp and listens
  server/auth.js           OIDC login / callback / logout, session, token refresh
  server/hub.js            Hub API client (user token or client credentials) + set cache
  server/tenancy.js        requireSession, org selection, tenant bootstrap
  server/competitorSets.js Hub-backed set endpoints and GA4 links
  server/webhooks.js       POST /hooks/hub
  server/migrations/002_hub.sql
  contract/v1/*.schema.json (vendored from the Hub, with CONTRACT_SOURCE)
  test/fakeHub.js, test/helpers.js, test/*.test.js
```

---

# Track H0: Foundation debts (Plan A Open Items 2-4)

### Task 1: Transaction-local tenant context

**Files:** Modify `app/Services/Tenancy/TenantContext.php`, `app/Http/Controllers/Auth/RegisterController.php`.
Create `tests/Feature/Tenancy/TenantContextScopeTest.php`, `tests/Unit/Tenancy/TenantContextGuardTest.php`.

**Interfaces:** Produces `TenantContext::run(string $orgId, Closure $fn): mixed`, `TenantContext::current(): ?string`.
`set()`/`clear()` keep their signatures but throw `LogicException` outside a transaction.

**Why:** session-scoped `set_config(..., false)` survives on a pooled or persistent connection (queue workers, PgBouncer,
Octane) into the next request or job. `is_local = true` ends with the transaction. GUC changes also roll back with a
savepoint, so an exception inside `run()` restores the previous value by itself.

- [ ] **Step 1: Failing tests**

```php
<?php
// tests/Unit/Tenancy/TenantContextGuardTest.php  (no RefreshDatabase: no transaction is open)
namespace Tests\Unit\Tenancy;

use App\Services\Tenancy\TenantContext;
use LogicException;
use Tests\TestCase;

class TenantContextGuardTest extends TestCase
{
    public function test_set_outside_a_transaction_is_refused(): void
    {
        $this->expectException(LogicException::class);
        TenantContext::set('00000000-0000-0000-0000-000000000001');
    }
}
```

```php
<?php
// tests/Feature/Tenancy/TenantContextScopeTest.php
namespace Tests\Feature\Tenancy;

use App\Models\Org;
use App\Models\Venue;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\TestCase;

class TenantContextScopeTest extends TestCase
{
    use RefreshesPrivilegedDatabase;

    public function test_run_scopes_context_and_restores_previous_value(): void
    {
        $a = Org::create(['name' => 'A']);
        $b = Org::create(['name' => 'B']);
        TenantContext::run($a->id, fn () => Venue::create(['org_id' => $a->id, 'name' => 'VA', 'segment' => 'cafe']));

        $this->assertNull(TenantContext::current());
        $seen = TenantContext::run($a->id, fn () => DB::select('select name from venues'));
        $this->assertSame(['VA'], array_column($seen, 'name'));

        TenantContext::run($b->id, function () use ($a, $b) {
            TenantContext::run($a->id, fn () => null);
            // The nested run must hand back the outer org, not null.
            $this->assertSame($b->id, TenantContext::current());
        });
    }

    public function test_exception_inside_run_leaves_no_context_behind(): void
    {
        $a = Org::create(['name' => 'A']);
        try {
            TenantContext::run($a->id, fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }
        $this->assertNull(TenantContext::current());
    }
}
```

Run: `php artisan test --filter=TenantContext` -> FAIL (`run`/`current` undefined, `set` does not throw).

- [ ] **Step 2: Implement**

```php
<?php
// api/app/Services/Tenancy/TenantContext.php
namespace App\Services\Tenancy;

use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

class TenantContext
{
    /**
     * Run $fn with app.current_org_id set for the duration of one transaction (a savepoint if one is already
     * open). The setting is transaction-local (set_config(..., true)), so it can never outlive the transaction
     * on a pooled or persistent connection. On success the previous value is restored explicitly, because a
     * RELEASEd savepoint would otherwise keep it until the outer transaction ends. On an exception the
     * savepoint rollback restores it.
     */
    public static function run(string $orgId, Closure $fn): mixed
    {
        return DB::transaction(function () use ($orgId, $fn) {
            $previous = self::current();
            self::set($orgId);
            $result = $fn();
            $previous === null ? self::clear() : self::set($previous);

            return $result;
        });
    }

    public static function set(string $orgId): void
    {
        self::assertInTransaction();
        DB::statement("SELECT set_config('app.current_org_id', ?, true)", [$orgId]);
    }

    public static function clear(): void
    {
        self::assertInTransaction();
        DB::statement("SELECT set_config('app.current_org_id', '', true)");
    }

    public static function current(): ?string
    {
        $value = DB::selectOne("SELECT current_setting('app.current_org_id', true) AS v")->v;

        return ($value === null || $value === '') ? null : $value;
    }

    private static function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Tenant context must be set inside a transaction; use TenantContext::run().');
        }
    }
}
```

In `RegisterController`, keep its existing `DB::transaction`, and delete the trailing `TenantContext::clear()` together
with its comment block (lines ~91-100). The transaction end now clears the context.

- [ ] **Step 3: Run the Plan A suite.** Tests that call `TenantContext::set()` directly still work because
  `RefreshDatabase` wraps each test in a transaction. `StatefulOriginGatingTest` depended on context leaking across
  requests (Plan A Open Item 2). Update it to set up its data inside `TenantContext::run()` and assert on what the
  request returns, not on leftover connection state.

Run: `php artisan test` -> all pass.

- [ ] **Step 4: Commit** `refactor: make tenant context transaction-local (Plan A open item 2)`

### Task 2: Tenant context for queued jobs

**Files:** Create `app/Jobs/Contracts/TenantScopedJob.php`, `app/Jobs/Middleware/RunsWithTenantContext.php`,
`tests/Feature/Tenancy/JobTenantContextTest.php`.

**Interfaces:** Produces the `TenantScopedJob` interface (`public function orgId(): string`) and job middleware
`RunsWithTenantContext`. Jobs that touch tenant tables implement the interface and return
`[new RunsWithTenantContext]` from `middleware()`.

- [ ] **Step 1: Failing test.** Define two test-only jobs inside the test file: one implementing `TenantScopedJob` with
  the middleware that counts `venues` rows into a static property, and one plain job that does the same without it.
  Create one venue in each of two orgs. Dispatch both with `dispatch_sync`. Assert the scoped job counts 1 and the plain
  job counts 0 (fail closed).
- [ ] **Step 2: Implement**

```php
<?php
// api/app/Jobs/Middleware/RunsWithTenantContext.php
namespace App\Jobs\Middleware;

use App\Jobs\Contracts\TenantScopedJob;
use App\Services\Tenancy\TenantContext;
use Closure;
use LogicException;

class RunsWithTenantContext
{
    public function handle(object $job, Closure $next): mixed
    {
        if (! $job instanceof TenantScopedJob) {
            throw new LogicException($job::class.' uses RunsWithTenantContext but does not implement TenantScopedJob.');
        }

        return TenantContext::run($job->orgId(), fn () => $next($job));
    }
}
```

- [ ] **Step 3:** `php artisan test` -> pass. **Commit** `feat: tenant-scoped job middleware`

### Task 3: Resolve the tenant from membership; remove the `X-Org-Id` header

**Files:** Create `app/Http/Middleware/ResolveTenant.php`, `app/Hub/Http/Problem.php`. Delete
`app/Http/Middleware/SetTenantContext.php` and `tests/Feature/Tenancy/SetTenantContextTest.php`. Modify `bootstrap/app.php`.
Create `tests/Feature/Tenancy/ResolveTenantTest.php`.

**Interfaces:** Middleware alias `tenant`, applied **after** authentication. Reads `X-Hub-Org`. On success it sets request
attributes `hub.org_id` and `hub.role` (`owner`/`manager`/`viewer`, or `tool` for client-credentials callers, added in
Task 12) and runs the rest of the request inside `TenantContext::run()`.
`Problem::response(int $status, string $type, string $title): JsonResponse` renders `application/problem+json` with
`type` = `https://hub/problems/<type>`.

- [ ] **Step 1: Failing tests** against a test-only route registered in the test's `setUp`
  (`Route::middleware(['auth:sanctum', 'tenant'])->get('/api/_probe', fn () => ['org' => request()->attributes->get('hub.org_id'), 'venues' => DB::table('venues')->count()])`):
  1. Member of org A, `X-Hub-Org: A` -> 200 and sees only A's venue.
  2. Member of A, `X-Hub-Org: B` -> **404** problem `org_not_found` (never 403, so org existence is not confirmed).
  3. No header -> 400 problem `org_required`. Not a UUID -> 404.
  4. `X-Org-Id: A` (the old header) -> 400 (it is ignored).
  5. After the request, `TenantContext::current()` is null.
- [ ] **Step 2: Implement**

```php
<?php
// api/app/Http/Middleware/ResolveTenant.php
namespace App\Http\Middleware;

use App\Hub\Http\Problem;
use App\Models\Membership;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ResolveTenant
{
    public function handle(Request $request, Closure $next)
    {
        $orgId = $request->header('X-Hub-Org');
        if (! $orgId) {
            return Problem::response(400, 'org_required', 'X-Hub-Org header is required.');
        }
        if (! Str::isUuid($orgId)) {
            return Problem::response(404, 'org_not_found', 'Organisation not found.');
        }

        return TenantContext::run($orgId, function () use ($request, $next, $orgId) {
            // RLS limits memberships to $orgId, so this is "is the user a member of $orgId".
            $role = $this->roleFor($request, $orgId);
            if ($role === null) {
                return Problem::response(404, 'org_not_found', 'Organisation not found.');
            }
            $request->attributes->set('hub.org_id', $orgId);
            $request->attributes->set('hub.role', $role);

            return $next($request);
        });
    }

    protected function roleFor(Request $request, string $orgId): ?string
    {
        $user = $request->user();

        return $user ? Membership::where('user_id', $user->id)->value('role') : null;
    }
}
```

In `bootstrap/app.php` remove `prependToGroup('api', SetTenantContext::class)` and add
`$middleware->alias(['tenant' => \App\Http\Middleware\ResolveTenant::class]);`.

- [ ] **Step 3:** Update RLS tests that used the header to use `TenantContext::run()`. `php artisan test` -> pass.
- [ ] **Step 4: Commit** `feat: resolve tenant from membership via X-Hub-Org; drop X-Org-Id`

### Task 4: Default privileges and an RLS / privilege catalog test

**Files:** Create migration `2026_10_01_000000_default_privileges_for_app_user.php`,
`tests/Feature/Tenancy/SchemaSecurityCatalogTest.php`.

- [ ] **Step 1: Failing test.** Two assertions, each reading the live catalog through the privileged `pgsql` connection:
  1. Every table in `public` with an `org_id` column has `relrowsecurity` and `relforcerowsecurity` and at least one row
     in `pg_policies`. Exception: an `ALLOWLIST_NO_RLS` constant mapping table name -> reason. It starts with
     `audit_log` ("append-only; app_user has INSERT only, and staff reads go through a separate role") and
     `webhook_outbox` (added in Task 18, "ids only, read only by the delivery job").
  2. Every table where `has_table_privilege('app_user', t, 'DELETE')` is true is listed in an `ALLOWED_DELETE` map with a
     reason. Start the map from what Plan A grants: `venues`, `memberships`, `password_reset_tokens`, `sessions`,
     `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `personal_access_tokens`.

  Then add a probe migration in the test (`Schema::connection('pgsql')->create('probe_t', ...)`) and assert `app_user`
  gets SELECT/INSERT/UPDATE on it without any explicit grant. This fails until Step 2.

- [ ] **Step 2: Migration**

```php
// up()
$owner = DB::connection('pgsql')->selectOne('select current_user as u')->u;
DB::connection('pgsql')->statement("ALTER DEFAULT PRIVILEGES FOR ROLE \"{$owner}\" IN SCHEMA public GRANT SELECT, INSERT, UPDATE ON TABLES TO app_user");
DB::connection('pgsql')->statement("ALTER DEFAULT PRIVILEGES FOR ROLE \"{$owner}\" IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO app_user");
// down(): the matching REVOKEs.
```

Note in the migration comment: default privileges apply to objects created later **by that role**. Migrations always
run as the privileged `pgsql` connection (Plan A), so that is the role to name.

- [ ] **Step 3:** `php artisan test` -> pass. **Commit** `feat: default app_user privileges and schema security catalog test (Plan A open item 4)`

### Task 5: `EnsureOwnerHasMfa`

**Files:** Create `app/Http/Middleware/EnsureOwnerHasMfa.php`, alias `mfa.owner`;
`tests/Feature/Auth/EnsureOwnerHasMfaTest.php`.

- [ ] **Step 1: Failing test.** Probe route with `['auth:sanctum', 'tenant', 'mfa.owner']`: owner without MFA -> 403 problem
  `mfa_required`; owner with MFA -> 200; manager without MFA -> 200 (the rule only binds owners, per 05 §5.3).
- [ ] **Step 2: Implement.** If `hub.role === 'owner'` and `! $request->user()->mfa_enabled`, return
  `Problem::response(403, 'mfa_required', 'Owners must enable MFA for this action.')`.
- [ ] **Step 3:** pass. **Commit** `feat: enforce MFA for owners on owner-only routes (Plan A open item 3)`

---

# Track H1: Identity provider

### Task 6: Install Passport and the OIDC layer

**Files:** `composer.json`, `bootstrap/providers.php`, `config/auth.php`, `config/openid.php` (published),
Passport migrations (published), migration `2026_10_02_000000_add_public_id_to_users.php`, `app/Models/User.php`,
`app/Providers/AppServiceProvider.php`, `app/Hub/Identity/FirstPartyClient.php`, `.gitignore`, `.env.example`,
`tests/Support/HubTokens.php`, `tests/Feature/Hub/AuthorizationCodeFlowTest.php`.

- [ ] **Step 1: Install**

```bash
composer require laravel/passport:^13.8 jeremy379/laravel-openid-connect:^3.3
```

Add to `composer.json`: `"extra": {"laravel": {"dont-discover": ["laravel/passport"]}}` (the package's provider extends
Passport's; both must not load). Run `php artisan package:discover`. Add
`OpenIDConnect\Laravel\PassportServiceProvider::class` to `bootstrap/providers.php`.

```bash
php artisan vendor:publish --tag=passport-migrations
php artisan vendor:publish --tag=openid
php artisan passport:keys
```

`.gitignore`: `storage/oauth-*.key`. `.env.example`: `PASSPORT_PRIVATE_KEY=` / `PASSPORT_PUBLIC_KEY=` (production reads
the keys from the secrets manager through these variables), `OPENID_FORCE_HTTPS=true`, `HUB_ISSUER=`.

- [ ] **Step 2: Config**

`config/auth.php` guards: add `'api' => ['driver' => 'passport', 'provider' => 'users']`.

`config/openid.php`:
- `passport.tokens_can` = `openid`, `email`, `profile`, `orgs`, `competitor-sets:read`, `competitor-sets:write` (drop the
  package's `phone` and `address`).
- `repositories.identity` = `App\Hub\Identity\HubIdentityRepository::class` (Task 7).
- `routes.userinfo` = `false` (Task 9 provides our own).
- `use_microseconds` = `false`, `forceHttps` = `env('OPENID_FORCE_HTTPS', true)`,
  `issuedBy` = `env('HUB_ISSUER', 'laravel')`. A fixed issuer in production stops a Host-header change from changing
  `iss`.

`AppServiceProvider::boot()`:

```php
Passport::useClientModel(FirstPartyClient::class);
Passport::tokensCan(config('openid.passport.tokens_can'));
Passport::tokensExpireIn(CarbonInterval::minutes(15));
Passport::refreshTokensExpireIn(CarbonInterval::days(30));
Passport::authorizationView(fn () => abort(403, 'Third-party clients are not supported.'));
```

`FirstPartyClient extends Laravel\Passport\Client` overrides
`skipsAuthorization(Authenticatable $user, array $scopes): bool { return $this->firstParty(); }`.
There are no third-party clients in Plan B, so no consent screen is built.

`User`: `implements Laravel\Passport\Contracts\OAuthenticatable`, `use Laravel\Passport\HasApiTokens`
(`User` does not use Sanctum's trait today, so the two do not collide).

- [ ] **Step 3: Migration `add_public_id_to_users`**

```php
DB::statement('ALTER TABLE users ADD COLUMN public_id uuid NOT NULL DEFAULT gen_random_uuid()');
DB::statement('ALTER TABLE users ADD CONSTRAINT users_public_id_unique UNIQUE (public_id)');
```

The Passport tables get SELECT/INSERT/UPDATE from Task 4's default privileges. Passport revokes by update, and
`passport:purge` runs as the privileged role, so no DELETE grant is needed.

- [ ] **Step 4: Test helper and failing flow test.** `tests/Support/HubTokens.php` provides
  `makeWebClient(): array{client, secret}` (a `FirstPartyClient::forceCreate` with
  `grant_types = ['authorization_code','refresh_token','client_credentials']`, redirect `https://web.test/auth/callback`),
  `pkce(): array{verifier, challenge}`, and `authorizeAndExchange(User $u, string $scope): array` (the spike's flow).
  `AuthorizationCodeFlowTest` asserts: redirect carries `code` and `state`; token response has `id_token`,
  `refresh_token`, `expires_in` <= 900; `id_token` `sub` = `public_id`, `nonce` echoed, `iat` is an integer;
  discovery has `issuer` and `jwks_uri`; JWKS has a key.
- [ ] **Step 5:** pass, plus the full suite. **Commit** `feat: Passport + OIDC provider foundation`

### Task 7: Identity claims

**Files:** `app/Hub/Identity/HubIdentityRepository.php`, `HubIdentityEntity.php` (the spike's code, shown below),
extend `AuthorizationCodeFlowTest`.

```php
class HubIdentityRepository implements IdentityRepositoryInterface
{
    public function getByIdentifier(string $identifier): IdentityEntityInterface
    {
        $user = User::findOrFail($identifier);          // Passport passes users.id
        $entity = new HubIdentityEntity($user);
        $entity->setIdentifier($user->public_id);         // becomes id_token.sub
        return $entity;
    }
}
// HubIdentityEntity::getClaims(): name, email, email_verified (email_verified_at !== null)
```

- [ ] Tests: `scope=openid` only -> no `email`/`name` in the id_token; `openid email` -> `email` and `email_verified`,
  no `name`. **Commit** `feat: id_token claims keyed by public_id`

### Task 8: Hosted login and MFA pages; S256-only PKCE

**Files:** `app/Hub/Http/Controllers/WebLoginController.php`, `resources/views/auth/login.blade.php`,
`resources/views/auth/mfa.blade.php`, `app/Hub/Http/Middleware/RequireS256Pkce.php`, `routes/web.php`,
`tests/Feature/Hub/HostedLoginTest.php`.

**Why:** `/oauth/authorize` redirects unauthenticated browsers to the route named `login`. Plan A's login is a JSON API
only.

Routes (`web` group, so CSRF and sessions apply):

| Route | Name | Middleware |
|---|---|---|
| `GET /login` | `login` | `guest` |
| `POST /login` | | `guest`, `throttle:login` |
| `GET /login/mfa` | `login.mfa` | `guest` |
| `POST /login/mfa` | | `guest`, `throttle:mfa-verify` |
| `POST /logout` | `logout` | `auth` |

Behaviour:
- `POST /login`: same credential check as `LoginController::login` (`Hash::check`, identical error message). If
  `mfa_enabled`, store `login.pending_user_id` and `login.pending_until` (now + `LoginController::MFA_TOKEN_TTL_MINUTES`)
  in the session and redirect to `login.mfa`. Otherwise log in.
- `POST /login/mfa`: pending state must exist and be unexpired. Verify with `Google2FA::verifyKey`, same as
  `MfaController::verify`.
- Log in: `Auth::guard('web')->login($user)`, `session()->regenerate()`, forget the pending keys, audit `login` with
  `meta = ['channel' => 'oidc']`, `redirect()->intended('/')`.
- Views: minimal HTML forms with `@csrf`. No JS, no third-party assets (CSP-friendly).

`RequireS256Pkce` is prepended to Passport's authorize route via
`Route::getRoutes()->getByName('passport.authorizations.authorize')?->middleware(RequireS256Pkce::class)` in
`AppServiceProvider::boot()` (after routes load: use `$this->app->booted(...)`). It returns a 400 OAuth error JSON when
`code_challenge` is missing or `code_challenge_method !== 'S256'`.

- [ ] **Tests:**
  1. Unauthenticated `GET /oauth/authorize?...` -> redirect to `/login`.
  2. `POST /login` (no MFA) -> redirect back to the authorize URL; following it yields a `code`.
  3. MFA user: `POST /login` -> `/login/mfa`; wrong code -> error, not logged in; right code -> redirect to authorize.
  4. Expired pending state -> back to `/login`.
  5. `code_challenge_method=plain` -> 400; missing `code_challenge` -> 400.
  6. Audit row `login` with channel `oidc` exists.
  7. The Plan A JSON login tests still pass unchanged.
- [ ] **Commit** `feat: hosted login and MFA pages for OIDC authorize; require S256 PKCE`

### Task 9: UserInfo and RP-initiated logout

**Files:** `app/Hub/Http/Controllers/UserInfoController.php`, `EndSessionController.php`, migration
`2026_10_02_100000_add_post_logout_redirect_uris_to_oauth_clients.php` (`json` column, default `[]`), `routes/web.php`,
`tests/Feature/Hub/UserInfoAndLogoutTest.php`.

- `GET /oauth/userinfo`, named **`openid.userinfo`** (the package's discovery controller advertises any route with this
  name), middleware `auth:api`. Returns `sub = public_id` plus the claims allowed by the token's scopes.
  Regression test: `sub` is never `(string) $user->id`.
- `GET /oauth/logout`, named **`openid.end_session_endpoint`** (same discovery mechanism), `web` middleware. Params:
  `id_token_hint` (required; verify the signature with the Passport public key and read `aud` as the client id),
  `post_logout_redirect_uri` (must exactly match one of that client's `post_logout_redirect_uris`), `state` (echoed).
  Logs out the web guard, invalidates the session, audits `logout` with channel `oidc`, then redirects. Invalid hint or
  URI -> 400, no redirect (never an open redirect).
- Tests: discovery now lists `userinfo_endpoint` and `end_session_endpoint`; userinfo scope filtering; logout happy path;
  unregistered redirect URI -> 400; tampered hint -> 400.
- [ ] **Commit** `feat: userinfo with public sub and RP-initiated logout`

### Task 10: Refresh-token reuse detection

**Files:** `app/Hub/Identity/ReuseDetectingRefreshTokenRepository.php`, binding in `AppServiceProvider::register()`,
`tests/Feature/Hub/RefreshTokenReuseTest.php`.

```php
class ReuseDetectingRefreshTokenRepository extends \Laravel\Passport\Bridge\RefreshTokenRepository
{
    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        $revoked = parent::isRefreshTokenRevoked($tokenId);
        if ($revoked) {
            $this->revokeFamily($tokenId);
        }
        return $revoked;
    }

    private function revokeFamily(string $tokenId): void
    {
        $token = Passport::refreshToken()->newQuery()->find($tokenId);
        $access = $token ? Passport::token()->newQuery()->find($token->access_token_id) : null;
        if (! $access) {
            return;                      // unknown or purged token: nothing to link it to
        }
        $accessIds = Passport::token()->newQuery()
            ->where('user_id', $access->user_id)->where('client_id', $access->client_id)->pluck('id');
        Passport::token()->newQuery()->whereIn('id', $accessIds)->update(['revoked' => true]);
        Passport::refreshToken()->newQuery()->whereIn('access_token_id', $accessIds)->update(['revoked' => true]);
        app(AuditLogger::class)->record('oauth.refresh_reuse', 'oauth_client', (string) $access->client_id,
            null, ['user_public_id' => User::find($access->user_id)?->public_id]);
    }
}
```

Bind: `$this->app->bind(\Laravel\Passport\Bridge\RefreshTokenRepository::class, ReuseDetectingRefreshTokenRepository::class);`
Both the package's auth-code grant and Passport's refresh grant resolve the repository from the container.

"Family" here is every token of that user for that client. Simpler and stricter than a per-grant lineage, and all Plan B
clients hold one grant per user session.

- [ ] Test: exchange -> refresh (RT1 -> RT2) -> replay RT1 -> 400; then RT2 -> 400 as well; the access token from RT2 is
  rejected by `/oauth/userinfo`; audit row exists.
- [ ] **Commit** `feat: revoke token family on refresh-token reuse`

---

# Track H2: Contract v1

### Task 11: JSON Schemas and contract assertions

**Files:** `contract/v1/{me,org,org-list,competitor-set,competitor-set-list,problem,webhook-event}.schema.json`,
`contract/v1/README.md`, `tests/Support/AssertsContract.php`; `composer require --dev opis/json-schema`.

- Schemas are JSON Schema 2020-12 with `additionalProperties: false` on every object, so adding a field is a deliberate
  schema change that shows up in review.
- `competitor-set`: `{id: uuid, name: string, tools: ["revenue"|"web"] (min 1, unique), version: integer,
  activated_at: date-time|null, composition_locked_until: date-time|null,
  members: [{id: uuid, name, website_url: uri|null, location_text: string|null, cuisine: string|null}]}`.
  Members never expose `market_id` or any matching data.
- `webhook-event`: `{id: uuid, event: enum, org_id: uuid, entity_id: uuid, occurred_at: date-time, contract: "v1"}`.
- `AssertsContract::assertMatchesContract(TestResponse|array $payload, string $schema)`.
- README: versioning rules (additive changes stay in v1 only if consumers ignore unknown fields: **they must not**,
  because of `additionalProperties: false`; so any change bumps the schema and is coordinated with the Web repo's
  vendored copy), and the opaque access-token rule.
- [ ] A self-test validates each schema against one hand-written valid and one invalid example. **Commit**
  `feat: contract v1 JSON Schemas`

### Task 12: Hub callers, org tool links, `/me`, `/me/orgs`, orgs, tool linking

**Files:** migrations `2026_10_03_000000_create_org_tool_links_table.php` (with RLS policy),
`2026_10_03_000100_add_hub_tool_to_oauth_clients.php`, `2026_10_03_000200_memberships_self_read_policy.php`;
`app/Hub/Http/HubCaller.php`, `Middleware/AuthenticateHubCaller.php`, `Middleware/RequireUserCaller.php`;
extend `ResolveTenant::roleFor()`; controllers `MeController`, `OrgController`, `ToolLinkController`; `routes/hub.php`
(prefix `hub/v1`, registered in `bootstrap/app.php` `withRouting(then: ...)`); `TenantContext::runAsUser()`;
tests `tests/Feature/Hub/{CallerAuthTest,MeTest,ToolLinkTest}.php`.

**Callers.** `AuthenticateHubCaller extends Laravel\Passport\Http\Middleware\ValidateToken`: validates the bearer token,
checks the scopes given as middleware parameters, and builds
`HubCaller { ?User $user, string $clientId, ?string $tool, array $scopes }`. `user` comes from `oauth_user_id` (bigint;
internal only). `tool` comes from `oauth_clients.hub_tool`. A client-credentials token (no user) is **refused any
`competitor-sets:write` scope** even if it was granted. If there is a user, it calls `Auth::setUser()` so policies and the
audit logger see them. `RequireUserCaller` returns 403 problem `user_required` for tool-only callers.

**Tenant for tools.** `ResolveTenant::roleFor()`: if the caller has no user but has a tool, return `'tool'` when an
active (`revoked_at IS NULL`) `org_tool_links` row exists for (org, tool) under the org's context; otherwise null (404).

**Listing a user's orgs across tenants.** RLS on `memberships` and `orgs` is keyed to one org, so add permissive
**SELECT-only** policies keyed to a second transaction-local setting:

```sql
CREATE POLICY memberships_self_read ON memberships FOR SELECT
  USING (user_id::text = current_setting('app.current_user_id', true));
CREATE POLICY orgs_member_read ON orgs FOR SELECT
  USING (id IN (SELECT org_id FROM memberships
                WHERE user_id::text = current_setting('app.current_user_id', true)));
```

`TenantContext::runAsUser(int $userId, Closure $fn)` mirrors `run()` for `app.current_user_id`. Add a RLS test: with only
the user setting, a user sees their own memberships across orgs and **nothing** of other users, and writes are still
rejected (the policies are `FOR SELECT`).

**Endpoints** (all under `auth.hub` = `AuthenticateHubCaller`):

| Route | Middleware | Response |
|---|---|---|
| `GET /hub/v1/me` | `auth.hub:openid`, `hub.user` | `{sub, email, name}` (schema `me`) |
| `GET /hub/v1/me/orgs` | `auth.hub:orgs`, `hub.user` | `{orgs: [{id, name, role}]}` via `runAsUser` |
| `GET /hub/v1/orgs/{org}` | `auth.hub:orgs`, `tenant` | `{id, name}`. `{org}` must equal `X-Hub-Org`, else 404 |
| `PUT /hub/v1/orgs/{org}/tools/{tool}` | `auth.hub`, `hub.user`, `tenant`, `mfa.owner`, owner only | Body `{external_tenant_ref}`. Upsert, 200 with link. Audit `tool.linked` |

`{tool}` must be `web`. Any other value -> 404. Emitting `org.tool_linked` is added in Task 18.

- [ ] **Tests:** user token with scopes -> 200 and schema-valid; missing scope -> 403; client-credentials token on `/me` ->
  403 `user_required`; client token on `/orgs/{org}` with no link -> 404, with link -> 200; manager on `PUT tools` -> 403;
  owner without MFA -> 403 `mfa_required`; owner with MFA -> 200 and idempotent; `{org}` / header mismatch -> 404; a user
  in two orgs sees both in `/me/orgs`, another user's orgs never appear.
- [ ] **Commit** `feat: hub v1 callers, orgs and tool links`

### Task 13: `geo_areas` and `venues.market_id` FK

**Files:** migration `2026_10_04_000000_create_geo_areas_table.php`, `app/Hub/Models/GeoArea.php`,
`database/seeders/GeoAreaFixtureSeeder.php` (AU -> WA -> Greater Perth -> Dianella; test fixture only),
`tests/Feature/Hub/GeoAreaTest.php`.

- Columns: `id uuid pk`, `parent_id uuid null fk`, `level text check in (market, region, state, country)`,
  `code text`, `name text`, unique `(level, code)`. Reference data: no `org_id`, no RLS; `app_user` SELECT only
  (`REVOKE INSERT, UPDATE` after the default grant).
- `ALTER TABLE venues ADD CONSTRAINT venues_market_id_fk FOREIGN KEY (market_id) REFERENCES geo_areas(id)`.
- Test: FK rejects an unknown id; `app_user` cannot insert into `geo_areas`.
- The ABS data load is **not** in this plan (it belongs with benchmarking).
- [ ] **Commit** `feat: geo_areas reference table and venues.market_id FK`

---

# Track H3: Competitor sets

### Task 14: Tables, RLS and models

**Files:** migration `2026_10_05_000000_create_competitor_sets_tables.php`,
`app/Hub/Models/{CompetitorSet,CompetitorSetMember}.php`, `tests/Feature/Hub/CompetitorSetRlsTest.php`.

```sql
CREATE TABLE competitor_sets (
  id uuid PRIMARY KEY, org_id uuid NOT NULL REFERENCES orgs(id),
  name text NOT NULL CHECK (length(name) BETWEEN 1 AND 120),
  tools text[] NOT NULL CHECK (cardinality(tools) >= 1 AND tools <@ ARRAY['revenue','web']),
  version integer NOT NULL DEFAULT 1,
  activated_at timestamptz NULL, composition_locked_until timestamptz NULL,
  created_by bigint NULL REFERENCES users(id),
  created_at timestamptz, updated_at timestamptz, deleted_at timestamptz NULL);
CREATE TABLE competitor_set_members (
  id uuid PRIMARY KEY, org_id uuid NOT NULL REFERENCES orgs(id),
  set_id uuid NOT NULL REFERENCES competitor_sets(id),
  name text NOT NULL CHECK (length(name) BETWEEN 1 AND 200),
  website_url text NULL, location_text text NULL, cuisine text NULL,
  market_id uuid NULL REFERENCES geo_areas(id),
  removed_at timestamptz NULL, created_at timestamptz, updated_at timestamptz);
-- members.org_id must equal the set's org_id:
ALTER TABLE competitor_sets ADD CONSTRAINT competitor_sets_id_org_unique UNIQUE (id, org_id);
ALTER TABLE competitor_set_members ADD CONSTRAINT members_set_same_org
  FOREIGN KEY (set_id, org_id) REFERENCES competitor_sets(id, org_id);
```

No `ON DELETE CASCADE` anywhere (sets soft-delete; members are marked `removed_at`), and no DELETE grant. RLS: ENABLE,
FORCE and a `USING`/`WITH CHECK` policy on `org_id`, as in Plan A.

- [ ] **Tests:** isolation between two orgs; inserting a member whose `org_id` differs from its set's -> constraint
  violation; `tools = '{}'` and `'{sms}'` rejected; the Task 4 catalog test passes with no allowlist change.
- [ ] **Commit** `feat: competitor set tables with RLS`

### Task 15: Read endpoints

**Files:** `CompetitorSetController@index/show`, `app/Hub/Http/Resources/CompetitorSetResource.php`,
`tests/Feature/Hub/CompetitorSetReadTest.php`.

| Route | Middleware |
|---|---|
| `GET /hub/v1/orgs/{org}/competitor-sets?tool=web` | `auth.hub:competitor-sets:read`, `tenant` |
| `GET /hub/v1/orgs/{org}/competitor-sets/{set}` | same |

- Visibility: tool callers see only sets whose `tools` contains their tool, and the `?tool` parameter is ignored. User
  callers may filter with `?tool=`. A hidden or soft-deleted set returns 404 on `show`.
- Members: only rows with `removed_at IS NULL`.
- `ETag`: `show` = `W/"<version>"`; `index` = `W/"<sha1 of sorted id:version pairs>"`. `If-None-Match` match -> 304.
- [ ] **Tests:** schema-valid responses; a `{revenue}`-only set is invisible to the web client; 304 path; cross-org set id
  -> 404; viewers can read.
- [ ] **Commit** `feat: competitor set read API`

### Task 16: Write endpoints

**Files:** `app/Hub/Services/CompetitorSetService.php` (every write goes through it), `CompetitorSetController@store/update/destroy`,
`CompetitorSetMemberController@store/update/destroy`, `tests/Feature/Hub/CompetitorSetWriteTest.php`.

| Route | Roles | Extra middleware |
|---|---|---|
| `POST .../competitor-sets` | owner, manager | `hub.user`, `auth.hub:competitor-sets:write` |
| `PATCH .../competitor-sets/{set}` | owner, manager | + `If-Match` required |
| `DELETE .../competitor-sets/{set}` | owner | + `mfa.owner` |
| `POST .../competitor-sets/{set}/members` | owner, manager | + `If-Match` |
| `PATCH .../members/{member}` | owner, manager | + `If-Match` |
| `DELETE .../members/{member}` | owner, manager | + `If-Match`, lock rules (Task 17) |

- Every change increments `version` in the same statement (`UPDATE ... SET version = version + 1 WHERE id = ? AND version = ?`).
  If no row is updated -> 412 problem `version_mismatch`. A missing `If-Match` -> 428 problem `precondition_required`.
- Validation: `website_url` must be `http(s)`; strings are trimmed; `tools` values come from the enum.
- Audit: `competitor_set.created|updated|deleted`, `competitor_set.member_added|updated|removed`, with `org_id`, no names in `meta`.
- Viewer or tool caller on any write -> 403.
- [ ] **Tests:** role matrix; version conflict -> 412; missing `If-Match` -> 428; audit rows; response schema-valid.
- [ ] **Commit** `feat: competitor set write API`

### Task 17: Activation and the composition lock

**Files:** `ActivationController`, `CompetitorSetService::removeMember()` rules, `config/hub.php`
(`composition_lock_days` = 30), `tests/Feature/Hub/CompositionLockTest.php`.

- `POST /hub/v1/orgs/{org}/competitor-sets/{set}/activation`: `auth.hub:competitor-sets:read`, `tenant`; tool **or** user
  callers. Sets `activated_at = now()` if null. Idempotent: 200 `{activated_at}` either way. The set must be visible to the
  caller (Task 15 rules).
- Removal rules for a set whose `tools` contains `web`:
  - not activated -> removal allowed, no lock;
  - activated and `composition_locked_until` in the future -> 409 problem `composition_locked` with `locked_until`;
  - activated and not locked -> removal allowed, and `composition_locked_until = now() + composition_lock_days`.
- Adding members and renaming are always allowed.
- Removing `web` from `tools`, or deleting the set, while it is locked -> 409 as well (both are "removals" for a consumer).
- [ ] **Tests:** each rule above using `Carbon::setTestNow()`; lock expiry at exactly 30 days.
- [ ] **Commit** `feat: set activation and composition lock against differencing`

---

# Track H4: Events

### Task 18: Outbox and webhook endpoints

**Files:** migration `2026_10_06_000000_create_webhook_tables.php`, `app/Hub/Models/{WebhookEndpoint,WebhookOutboxEntry}.php`,
`app/Hub/Events/HubEvents.php`, calls from `CompetitorSetService` and `ToolLinkController`,
`php artisan hub:webhook-endpoint {tool} {url}` command, `tests/Feature/Hub/OutboxTest.php`.

- `webhook_endpoints(id uuid, tool text, url text, secret text, previous_secret text null, active bool, timestamps)`.
  `secret` and `previous_secret` use Laravel's `encrypted` cast (platform config, not tenant data, same as `mfa_secret`).
- `webhook_outbox(id uuid, tool, event, org_id, entity_id, occurred_at, attempts int default 0, next_attempt_at,
  delivered_at null, failed_at null, last_error text null)`. Add it to the catalog test's `ALLOWLIST_NO_RLS`.
- `HubEvents::record(string $event, string $orgId, string $entityId, array $tools)` inserts one row per tool that has an
  active endpoint, **on the current connection**, so it commits or rolls back with the change. Called:
  - from every `CompetitorSetService` write with the set's `tools` (for a `tools` change: the union of old and new, so a tool
    that lost access hears about it and drops its cache);
  - from `ToolLinkController` with `[$tool]` for `org.tool_linked`.
- [ ] **Tests:** a successful write creates exactly one outbox row for `web`; a write that throws after `record()` leaves
  no row; a `{revenue}` set produces no `web` row; the payload has ids only.
- [ ] **Commit** `feat: transactional webhook outbox`

### Task 19: Delivery, signing and retries

**Files:** `app/Hub/Webhooks/WebhookSigner.php`, `app/Hub/Jobs/DeliverWebhook.php`, `hub:deliver-webhooks` command
scheduled every minute in `routes/console.php`, `tests/Feature/Hub/WebhookDeliveryTest.php`.

- Body: the outbox row as `webhook-event` JSON (schema-validated in the test).
- Headers: `Hub-Event-Id`, `Hub-Timestamp` (unix seconds),
  `Hub-Signature: v1=<hex hmac_sha256(secret, timestamp + "." + body)>`. While `previous_secret` is set, append
  `, v1=<hex with previous_secret>` so the consumer can rotate without downtime.
- `DeliverWebhook` is **not** tenant-scoped and reads only the outbox. It is dispatched `->afterCommit()` by `HubEvents`.
  The scheduled command also picks up rows where `delivered_at IS NULL AND failed_at IS NULL AND next_attempt_at <= now()`,
  so a missed dispatch is not lost. It takes a row lock (`FOR UPDATE SKIP LOCKED`) so two workers never double-send.
- HTTP: `Http::timeout(5)->withHeaders(...)->withBody($json, 'application/json')->post($url)`. 2xx -> `delivered_at`.
  Otherwise `attempts++`, `next_attempt_at = now() + min(2^attempts, 360) minutes`, `last_error` = status or exception class
  (never the response body). After 24 hours since `occurred_at` -> `failed_at`, and `Log::warning` with the event id.
- [ ] **Tests** (`Http::fake`): signature verifies with the known secret; the rotation header has two signatures; 500 ->
  rescheduled with backoff; after 24h -> failed; delivered rows are not re-sent; the job runs with no tenant context and
  still succeeds.
- [ ] **Commit** `feat: signed webhook delivery with retries and secret rotation`

**Hub track done:** run `php artisan test` (whole suite) and `./vendor/bin/pint --test`. Then register the local Web
client for development:

```bash
php artisan tinker --execute='App\Hub\Identity\FirstPartyClient::forceCreate([...])'  # or a hub:client command
```

Add a `hub:client web {redirect} {post_logout_redirect}` artisan command in this step instead of relying on tinker, and
print the client id and secret once.

---

# Track W1: Web tool authentication (repo `traffic-dashboard`)

Conventions for this track: ES modules, Node 22, `node --test`. Test DB is a local Postgres named by
`TEST_DATABASE_URL`, rebuilt by the test helper from `server/schema.sql` and `server/migrations/*.sql`.

### Task 20: Make the server testable and add the fake Hub

**Files:** `server/app.js` (new; everything from `server/index.js` except `listen`), `server/index.js` (boot only),
`package.json` scripts `"test": "node --test test/"`, dev deps `supertest`, `jose`; `test/helpers.js`, `test/fakeHub.js`,
`test/smoke.test.js`.

- `createApp({ pool, ga, hub, config })`: `ga.runReport` is injected so tests never call Google. The default wiring in
  `index.js` passes the real `googleapis` client.
- `fakeHub.js` starts an Express server on an ephemeral port (`listen(0)`) with an RSA key pair from `jose`:
  - `/.well-known/openid-configuration`, `/oauth/jwks`;
  - `/oauth/authorize`: immediately redirects to `redirect_uri` with `code` = base64url of `{nonce, sub, code_challenge}`
    and the `state`;
  - `/oauth/token`: `authorization_code` (checks the PKCE verifier against the challenge and returns an id_token signed
    with `nonce`), `refresh_token`, `client_credentials`;
  - `/hub/v1/*`: served from in-memory fixtures that tests can change (`fakeHub.state.orgs`, `.sets`, `.links`).
    Every fixture response is validated against `contract/v1` schemas (Task 23), so the fake cannot drift from the Hub.
  - It records requests (`fakeHub.calls`) for assertions.
- `test/smoke.test.js`: `GET /health` -> 200 through `createApp`.
- [ ] **Commit** `test: node test harness, injectable app, fake Hub`

### Task 21: OIDC login, sessions, logout

**Files:** `server/auth.js`, `server/migrations/002_hub.sql` (session table for `connect-pg-simple`), deps
`openid-client@^6.8`, `express-session`, `connect-pg-simple`; `test/auth.test.js`; `.env.example`
(`HUB_ISSUER`, `HUB_CLIENT_ID`, `HUB_CLIENT_SECRET`, `HUB_REDIRECT_URI`, `HUB_POST_LOGOUT_REDIRECT_URI`, `SESSION_SECRET`,
`FRONTEND_ORIGIN`).

- `const oidc = await client.discovery(new URL(HUB_ISSUER), id, secret, client.ClientSecretPost(secret))`. Add
  `{ execute: [client.allowInsecureRequests] }` **only** when `NODE_ENV === 'test'` or the issuer is `http://127.0.0.1`.
- `GET /auth/login`: create a PKCE verifier, state and nonce, keep them in the session, redirect to
  `buildAuthorizationUrl` with scope `openid email profile orgs competitor-sets:read competitor-sets:write offline_access`.
- `GET /auth/callback`: `authorizationCodeGrant(oidc, currentUrl, { pkceCodeVerifier, expectedState, expectedNonce })`.
  Regenerate the session id, store `{ sub, accessToken, refreshToken, expiresAt, idToken }`, delete the PKCE values,
  redirect to `FRONTEND_ORIGIN`.
- `GET /auth/logout`: destroy the session, redirect to `buildEndSessionUrl(oidc, { id_token_hint, post_logout_redirect_uri })`.
- `session()`: `connect-pg-simple` store, cookie `{ httpOnly: true, sameSite: 'lax', secure: NODE_ENV === 'production' }`,
  `app.set('trust proxy', 1)` in production.
- `accessTokenFor(req)`: refreshes with `refreshTokenGrant` when less than 60 s remain and stores the rotated refresh
  token. If refresh fails (e.g. reuse revoked the family), destroy the session and answer 401 `reauth_required`.
- CORS: `origin: FRONTEND_ORIGIN, credentials: true`.
- [ ] **Tests** (fake Hub + supertest agent): full login sets a session; a wrong `state` -> 400 and no session; the
  callback cannot be replayed; logout redirects to the fake Hub's end-session URL with `id_token_hint`; an expired token
  triggers one refresh; a failed refresh -> 401 and the session is gone.
- [ ] **Commit** `feat: sign in through the Hub (OIDC code + PKCE) with server-side sessions`

### Task 22: Session-derived tenancy; admin guard

**Files:** `server/tenancy.js`, `server/hub.js` (`hubFetch(req, path, { method, body, ifMatch })` adds the bearer token
and `X-Hub-Org`), `002_hub.sql` (`ALTER TABLE tenants ADD COLUMN hub_org_id uuid UNIQUE`), `server/app.js`,
`test/tenancy.test.js`.

- Delete `requireTenant`. Remove every use of `x-tenant-id` and `DEV_TENANT_ID` from the server, `.env.example` and README.
- `GET /api/session` -> `{ user: {sub, email, name}, orgs: [...from /hub/v1/me/orgs], selectedOrgId, webEnabled }`, or
  401 when signed out.
- `POST /api/session/org {orgId}`: must be one of the user's orgs from the Hub, else 404.
- `requireSession` (on every `/api/*` route except `/api/session*`): 401 without a session; 409 `org_selection_required`
  without a selected org; loads `tenants.id` by `hub_org_id`; if none -> 403 `web_not_enabled`. Sets `req.tenantId`.
- `POST /api/tenant/enable` (session, selected org): the Hub decides who may do this. The Web backend creates the tenant
  row (`hub_org_id`, name from the Hub) in a transaction, calls `PUT /hub/v1/orgs/{org}/tools/web` with
  `{external_tenant_ref: tenant.id}`, and commits only if the Hub returned 2xx. A Hub 403 (not owner, or no MFA) is passed
  through as-is, so the UI can say "ask your owner" or "enable MFA".
- `/api/admin/*`: when `NODE_ENV !== 'development'`, answer 404. Staff authentication is a separate design (design open item 3).
- [ ] **Tests:** `x-tenant-id` header has no effect (401 without a session); a user in orgs A and B can select only those;
  web not enabled -> 403; enabling as owner creates the tenant and calls the Hub; a Hub 403 leaves no tenant row;
  admin routes are 404 in `production`.
- [ ] **Commit** `feat: tenant from Hub session; remove header tenancy; gate admin API`

### Task 23: Vendor the contract; frontend session handling

**Files:** `contract/v1/*.schema.json` + `contract/CONTRACT_SOURCE` (Hub repo commit SHA),
`test/contract.test.js` (every fake-Hub fixture validates, using `ajv` as a dev dependency),
`src/api.js`, `src/dashboard.jsx` (sign-in and unavailable states only), `admin/main.jsx`.

- `src/api.js`: drop `x-tenant-id`, `VITE_TENANT_ID` and `DEMO_MODE`; every `fetch` uses `credentials: 'include'`.
  One `request()` helper: 401 -> `window.location = API_URL + '/auth/login'`; 403 `web_not_enabled` and 409
  `org_selection_required` raise typed errors the dashboard renders as screens ("Enable Web Performance for {org}" with
  an owner-only button calling `/api/tenant/enable`, and an org picker).
- `dashboard.jsx:2175` shows a banner when `VITE_TENANT_ID` is missing. Replace it with the session-driven states above.
- Build check: `npm run build && npm run build:admin && npm run lint`.
- [ ] **Commit** `feat: vendored contract v1; frontend uses the cookie session`

---

# Track W2: Web tool competitor sets

### Task 24: GA4 links, set cache and Hub-backed set API

**Files:** `002_hub.sql` additions; `server/competitorSets.js`; `server/hub.js` cache functions; `test/competitorSets.test.js`.

```sql
CREATE TABLE hub_member_ga4_links (
  tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  hub_member_id uuid NOT NULL, ga4_property_id text NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (tenant_id, hub_member_id));
CREATE TABLE hub_set_cache (
  tenant_id uuid NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  set_id uuid NOT NULL, etag text, payload jsonb NOT NULL, fetched_at timestamptz NOT NULL,
  PRIMARY KEY (tenant_id, set_id));
CREATE TABLE hub_webhook_events_seen (event_id uuid PRIMARY KEY, received_at timestamptz NOT NULL DEFAULT now());
-- Pitch data is discarded (design decision B3):
DELETE FROM properties WHERE role = 'competitor';
ALTER TABLE properties DROP COLUMN competitor_set, DROP COLUMN chosen_competitor;
```

(`report_snapshots.competitor_set` keeps its name and now stores a Hub set id or `market`/`cuisine`.)

- `getSet(req, setId)`: use the cache if younger than 5 minutes, else fetch with `If-None-Match`. On 304, touch `fetched_at`.
  On 404, delete the cache row and return null.
- Endpoints (all `requireSession`): `GET /api/competitor-sets` (Hub list `?tool=web`); `GET /api/competitor-sets/:id` (set
  plus, for each member, `ga4Linked: boolean`, **never** the property id of another business in list responses; the id is
  only returned on the member's own GA4 link endpoint);
  `POST`/`PATCH`/`DELETE` proxies with the user's token and `If-Match` passed through (the Hub's 409/412/428 are returned as-is);
  `PUT /api/competitor-sets/:id/members/:memberId/ga4 {ga4PropertyId}` and `DELETE` the same path (local table, member
  must belong to the set). Every write invalidates that set's cache row.
- Validate `ga4PropertyId` as digits only.
- [ ] **Tests:** proxy passes `If-Match` and returns the Hub's 412; the cache is used within 5 minutes and refetched after;
  a GA4 link for a member not in the set -> 404; other tenants' links are invisible.
- [ ] **Commit** `feat: Hub-backed competitor sets with local GA4 links`

### Task 25: Benchmark threshold 5, unavailable state, activation

**Files:** `server/app.js` (`dashboard()`), `server/competitorSets.js` (`membersForBenchmark`), `test/dashboard.test.js`.

- `competitorSet` query parameter: a Hub set UUID, `market` or `cuisine`.
  - Set id: members of that set with a GA4 link.
  - `market` / `cuisine`: members across all the tenant's web-visible sets whose `location_text` / `cuisine`
    (case-insensitive, trimmed) equals the query, de-duplicated by `ga4_property_id`.
- Fewer than **5** GA4-linked competitors -> `422 {status: "unavailable", reason: "not_enough_competitors"}`. No count
  and no member names in the response.
- **Before** calling GA4, `POST .../activation` for every contributing set (client-credentials token). If any activation
  fails -> 503 `hub_unavailable` and nothing is returned. Activation must precede data, or a benchmark could be seen
  before the lock applies.
- The other set-dependent endpoints (`/api/insights`, `/api/behavior-overview`, `/api/top-search-queries`) read
  `report_snapshots`, which only `dashboard()` writes after passing this gate, so they need no separate check. Add a test
  proving it.
- [ ] **Tests:** 4 linked -> 422 with no count; 5 linked -> 200 and activation called once per set before any GA4 call
  (order asserted with the fake GA and `fakeHub.calls`); activation failure -> 503 and no GA call; market mode dedupes
  shared GA4 properties.
- [ ] **Commit** `feat: web benchmarks need 5 linked competitors and activate sets first`

### Task 26: Webhook receiver

**Files:** `server/webhooks.js`, `test/webhooks.test.js`, `.env.example` (`HUB_WEBHOOK_SECRET`).

- `POST /hooks/hub` with `express.raw({ type: 'application/json' })` (the signature covers the exact bytes).
- Reject (400, no detail) when: the timestamp is missing or more than 300 s from now; no `v1=` value matches
  `hmac_sha256(secret, ts + "." + rawBody)` under `crypto.timingSafeEqual`; the body fails the `webhook-event` schema.
- `INSERT INTO hub_webhook_events_seen ... ON CONFLICT DO NOTHING`. If nothing was inserted, return 200 (duplicate).
- `competitorset.changed`: delete `hub_set_cache` rows for `entity_id` for the tenant with `hub_org_id = org_id`.
  `org.tool_linked` / `org.updated`: refresh the tenant name via the client-credentials token.
- Always return 2xx quickly for valid events (the Hub retries otherwise). Do the refetch lazily on next read.
- A daily cleanup deletes `hub_webhook_events_seen` rows older than 7 days.
- [ ] **Tests:** valid signature -> cache row gone; stale timestamp -> 400; bad signature -> 400; rotation header with the
  second signature valid -> accepted; replayed event id -> 200 and no second effect.
- [ ] **Commit** `feat: verify Hub webhooks and invalidate set cache`

### Task 27: End-to-end check and docs

**No new code.** Run the real pair locally.

- [ ] Hub: `php artisan migrate`, `php artisan hub:client web http://localhost:8787/auth/callback http://localhost:5173/`,
  `php artisan hub:webhook-endpoint web http://localhost:8787/hooks/hub`, `php artisan serve`, `php artisan queue:work`,
  `php artisan schedule:work`.
- [ ] Web: `.env` from the printed client id/secret, `npm run server`, `npm run dev`.
- [ ] Walk through and tick: register on the Hub -> enable MFA -> open the Web app -> redirected to the Hub login -> MFA ->
  back in the Web app -> "Enable Web Performance" -> create a set with 5 members, link 5 GA4 properties -> dashboard shows
  the benchmark -> remove a member -> 409 locked -> rename the set in the Hub API -> the Web app shows the new name
  after the webhook -> sign out -> the Hub session is gone too.
- [ ] Update both READMEs (setup, env vars, the same-site deployment constraint for the session cookie) and
  `07-decisions-and-open-questions.md` (mark open question 7 closed; add the design's open items).
- [ ] **Commit** in each repo: `docs: Plan B setup and end-to-end check`

---

## Implementation notes: Hub track (Tasks 1-19 done, 2026-09-29)

All Hub tasks are implemented on `claude/sleepy-fermat-jc39ms`; the suite is 171 tests, all passing. Where the
code differs from the task text above, the code is right and this section says why:

- **Task 1:** the interim `SetTenantContext` would have thrown on every production `/api` request once `set()`
  required a transaction (tests hid it: `RefreshDatabase` wraps each test in one). It was switched to `run()` in the
  same commit, with a transaction-free test. Step 3's predicted `StatefulOriginGatingTest` rewrite was not needed.
- **Task 3:** `tenant` is a route middleware, so it is placed in Laravel's middleware priority list
  (auth < `tenant` < `SubstituteBindings`) to keep Plan A's rule that route-model binding runs under tenant
  context. A bound-model test fails without it.
- **Task 6:** Passport's published migrations are renamed to sort after Task 4's default-privileges migration, or
  `app_user` would get no grants on the OAuth tables. `HUB_ISSUER` falls back with `?:` because an empty env value
  is not "unset".
- **Task 8:** the TOTP step uses a new `web-mfa` limiter keyed on the pending user in the session; the plan's
  `mfa-verify` keys on an `mfa_token` field the hosted flow does not have (it would degrade to per-IP only).
- **Task 12:** for client-credentials tokens league/oauth2-server puts the client id in the token subject, so "no
  user" is `sub == client id`. A client-credentials request for `openid` is refused as `invalid_scope` (it was a
  500). Laravel converts authorization/not-found exceptions to HTTP exceptions *before* render callbacks, so the
  `/hub/*` problem renderer maps by status.
- **Task 16:** refusals are thrown (`HubProblem`), not returned, so the request's tenant transaction rolls back the
  whole write, version bump included.
- **Task 19:** instead of holding `FOR UPDATE SKIP LOCKED` across the HTTP call, a worker claims an entry with a
  one-minute lease (conditional `UPDATE`) and sends outside any transaction.
- **Extra:** `hub:client` registers a tool's OAuth client. The base `TestCase` blocks stray HTTP.
- **Test harness:** Laravel caches each route's controller on the `Route` object, and Passport's
  `AuthorizationController` keeps the guard it was built with, so a second sign-in within one test issued codes for
  the first user. `HubTokens` resets both before each authorize. Production builds a fresh app per request; **under
  Octane this caching would need the same care** (add to Plan A's Octane note).
- **Not done:** `laravel/boost` (asked for by `api/CLAUDE.md`) was not installed; it is outside Plan B's scope.
  `pint --test` is not a usable gate: nearly every existing file fails it on the house style's `// api/...` comment
  after `<?php`.

## Self-review

- **Spec coverage.** B1 -> Tasks 6-10; B2/B3 -> Tasks 22, 24 (no linking, demo data dropped); B4 -> Tasks 16, 24;
  B5 -> Tasks 14, 24; B6 -> Task 25; B7 -> Tasks 18-19, 26; B8 -> Tasks 1-2; B9 -> file layout (`App\Hub`, `public` schema).
  Design §4.2 -> Tasks 3, 12; §4.4 -> Tasks 12-14, 18; §4.5 -> Tasks 12, 15-17; §4.6 -> Tasks 18-19; §4.7 -> Tasks 17, 25;
  §4.8 -> Tasks 5, 8; §5 -> Tasks 20-26; §6 -> each task's tests plus Task 23's contract check.
- **Changed from the design after the spike:** no `amr`/`auth_time` claims (finding 7). Tenant selection for SPA
  requests uses the same `X-Hub-Org` header plus a membership check as other callers, instead of a session-selected org.
  One mechanism, same security.
- **Not in this plan:** venue matching, `venue_competitor_sets`, revenue competitor-set aggregates, contribution consent,
  the ABS geo load, the strategy store (W3), staff/admin authentication, the AWS KMS driver.

## Open Items (raised by this plan)

1. The OIDC package is maintained by one person and its README trails Laravel 13. Pin `^3.3` and keep
   `AuthorizationCodeFlowTest` as the upgrade gate. The fallback, if it breaks, is to keep Passport and move the
   `IdTokenResponse` class into `App\Hub\Identity` (it is ~150 lines).
2. The Passport signing key pair must come from the secrets manager in production (`PASSPORT_PRIVATE_KEY`), with a
   documented rotation (publish the new key in JWKS before signing with it). Rotation tooling is not in this plan.
3. The Web tool's deployment domains must be same-site before Task 21's cookie session works outside localhost.
4. Web staff/admin authentication is still undesigned; the admin API is off outside development until it is.
5. **Differencing is not fully prevented (design §4.7 needs a decision before W2).** The composition lock
   rate-limits removals to one per 30 days, but one removal is enough: with 6 GA4-linked members, remove one (5
   remain, still available) and re-query the *same past date window*; `6 x avg_before - 5 x avg_after` is the removed
   competitor's traffic. Proposed fix: composition by date. A removed member keeps counting in any window that starts
   before its `removed_at`, so re-querying an old window returns the same answer. This needs removed members (with
   `removed_at`) in the tool-facing contract and a change to Task 25's averaging.
6. **Repo visibility:** Plan A's Open Item 5 still applies. `For-Claude-Cloud` is public. Make it private once cloud
   sessions no longer need it.
