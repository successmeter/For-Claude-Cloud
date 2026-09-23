# Foundation (Plan A of 4) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the Revenue Performance Benchmarking Laravel app's foundation: project scaffold, Postgres with row-level security, per-org envelope encryption, authentication with MFA, the org/venue/user/membership tenancy model, and an append-only audit log.

**Architecture:** A single Laravel app (`api/`) using Postgres and Laravel Sail (Docker) for local dev. Tenancy is enforced twice: Eloquent global scopes for convenience, and Postgres row-level security (RLS) as the layer that holds even if application code forgets a `WHERE`. This plan produces no UI and no OIDC/ingestion/benchmarking code — those are Plans B, C and D. Every task in this plan is testable with `php artisan test` alone; no cloud credentials are required.

**Tech Stack:** PHP (Laravel, latest stable at scaffold time — verify version with `composer show laravel/framework` after Task 1), Postgres 15+, Laravel Sail (Docker), Sanctum (session auth), PHPUnit (Laravel's default test runner), `pragmarx/google2fa-laravel` for TOTP MFA, `spatie/laravel-permission`-style hand-rolled policies (see Task 9 rationale).

**Spec:** `revenue-benchmarking/01-architecture.md`, `revenue-benchmarking/02-data-model.md`, `revenue-benchmarking/05-security.md`

## Global Constraints

- All tenant-owned tables carry `org_id` and must be covered by an RLS policy before they hold real data (05-security.md §5.4).
- Postgres RLS keys off a per-request session variable `app.current_org_id`; the application's DB role is **not** a superuser and cannot bypass RLS (05-security.md §5.4, §5.2).
- Owners must have MFA (TOTP) enabled; other roles do not require it at this phase (05-security.md §5.3).
- Roles are exactly: `owner`, `manager`, `viewer` (02-data-model.md §2.1).
- The `audit_log` table is append-only: the app's DB role may INSERT but not UPDATE or DELETE (02-data-model.md §2.6, 05-security.md §5.6).
- Credentials and other sensitive fields use application-level envelope encryption with a per-org data key wrapped by a master key; deleting an org's key must render its ciphertext permanently unreadable ("crypto-shredding") (05-security.md §5.2).
- `sales_daily` and other bulk metric values are explicitly **not** covered by envelope encryption (storage-level only) — do not add it in this or later plans without a design change (05-security.md §5.2).
- No cloud KMS integration in this plan — see Task 4's Local KMS driver and the note in Open Items below.

---

## File Structure

```
revenue-benchmarking/
  api/                              <- new Laravel app (this plan creates it)
    app/
      Models/Org.php
      Models/Venue.php
      Models/User.php
      Models/Membership.php
      Models/AuditLogEntry.php
      Services/Encryption/KeyManagementService.php   (interface)
      Services/Encryption/LocalFileKmsDriver.php
      Services/Encryption/EnvelopeEncryptor.php
      Services/Tenancy/TenantContext.php
      Services/Audit/AuditLogger.php
      Http/Middleware/SetTenantContext.php
      Http/Controllers/Auth/RegisterController.php
      Http/Controllers/Auth/LoginController.php
      Http/Controllers/Auth/MfaController.php
      Policies/VenuePolicy.php
    database/migrations/
      ..._create_orgs_table.php
      ..._create_users_table.php          (extends Laravel's default)
      ..._create_venues_table.php
      ..._create_memberships_table.php
      ..._create_audit_log_table.php
      ..._enable_rls_on_tenant_tables.php
    tests/
      Unit/Services/EnvelopeEncryptorTest.php
      Feature/Tenancy/RowLevelSecurityTest.php
      Feature/Auth/RegistrationTest.php
      Feature/Auth/LoginTest.php
      Feature/Auth/MfaTest.php
      Feature/Tenancy/MembershipPolicyTest.php
      Feature/Audit/AuditLogTest.php
  plans/
    2026-09-23-foundation-implementation-plan.md   (this file)
```

---

### Task 0: Confirm local environment (Docker + Sail)

**Files:** none (verification only)

**Interfaces:** none

- [ ] **Step 1: Install prerequisites (manual, one-time)**

On Windows: install Docker Desktop, enable WSL2 integration. If WSL2 isn't installed, run in an elevated PowerShell:

```powershell
wsl --install
```

Reboot if prompted, then start Docker Desktop and confirm it shows "Running".

- [ ] **Step 2: Verify Docker is available**

Run: `docker --version && docker compose version`
Expected: both print version numbers, no errors. If `docker` is not found, stop and fix Step 1 before continuing — no later task in this plan can run without it.

- [ ] **Step 3: Verify WSL2 (Windows only)**

Run: `wsl -l -v`
Expected: at least one distro listed with `VERSION 2`.

No commit for this task — it produces no repo changes.

---

### Task 1: Scaffold the Laravel app with Sail

**Files:**
- Create: `revenue-benchmarking/api/` (entire Laravel project, generated)
- Create: `revenue-benchmarking/.gitignore`

**Interfaces:**
- Produces: a running Laravel app reachable at `http://localhost` via `./vendor/bin/sail up -d`, with Postgres reachable inside the `pgsql` (or `postgres`) Sail service.

- [ ] **Step 1: Initialize the repo (if not already a git repo)**

Run from `revenue-benchmarking/`:
```bash
git status || git init
```

- [ ] **Step 2: Scaffold Laravel via Sail's installer**

This uses Laravel's official installer image, so no local PHP/Composer is needed. From `revenue-benchmarking/`:

```bash
docker run --rm -v "$(pwd)":/opt -w /opt laravelsail/php84-composer:latest \
  composer create-project laravel/laravel api
```

(If `php84` is no longer current at execution time, check https://hub.docker.com/r/laravelsail/php84-composer for the current tag and substitute it.)

- [ ] **Step 3: Add Sail and select Postgres + Redis services**

```bash
cd api
docker run --rm -v "$(pwd)":/opt -w /opt laravelsail/php84-composer:latest \
  composer require laravel/sail --dev
docker run --rm -v "$(pwd)":/opt -w /opt laravelsail/php84-composer:latest \
  php artisan sail:install --with=pgsql,redis
```

- [ ] **Step 4: Set the app to use Postgres in `.env`**

Edit `api/.env`, confirm/set:
```
DB_CONNECTION=pgsql
DB_HOST=pgsql
DB_PORT=5432
DB_DATABASE=revenue_benchmarking
DB_USERNAME=sail
DB_PASSWORD=password
```

- [ ] **Step 5: Bring the stack up and verify**

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate
```

Expected: migration output shows Laravel's default migrations running against Postgres with no errors.

- [ ] **Step 6: Add a root `.gitignore` entry and commit**

Create `revenue-benchmarking/.gitignore` if it doesn't already cover it:
```
api/vendor/
api/node_modules/
api/.env
api/storage/*.key
```

```bash
cd ..   # back to revenue-benchmarking/
git add api .gitignore
git commit -m "chore: scaffold Laravel app with Sail (Postgres + Redis)"
```

---

### Task 2: Orgs, venues, users, memberships (tenancy model)

**Files:**
- Create: `api/database/migrations/..._create_orgs_table.php`
- Create: `api/database/migrations/..._create_venues_table.php`
- Create: `api/database/migrations/..._create_memberships_table.php`
- Modify: `api/database/migrations/..._create_users_table.php` (Laravel's default — add `org_id` is **not** added here; users belong to orgs only via `memberships`, since a user may later belong to more than one org)
- Create: `api/app/Models/Org.php`
- Create: `api/app/Models/Venue.php`
- Create: `api/app/Models/Membership.php`
- Modify: `api/app/Models/User.php`
- Test: `api/tests/Feature/Tenancy/TenancyModelsTest.php`

**Interfaces:**
- Produces: `Org::id`, `Venue::org_id`, `Membership::{user_id, org_id, role}` where `role` is one of `owner|manager|viewer`. `User::memberships()` (HasMany), `Org::venues()` (HasMany), `Org::memberships()` (HasMany).

- [ ] **Step 1: Write the failing test**

```php
<?php
// api/tests/Feature/Tenancy/TenancyModelsTest.php

namespace Tests\Feature\Tenancy;

use App\Models\Membership;
use App\Models\Org;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenancyModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_org_has_many_venues(): void
    {
        $org = Org::create(['name' => 'Test Org']);
        $venue = Venue::create([
            'org_id' => $org->id,
            'name' => 'Test Venue',
            'timezone' => 'Australia/Perth',
            'segment' => 'restaurant',
            'cuisine' => 'italian',
        ]);

        $this->assertTrue($org->venues->contains($venue));
        $this->assertEquals($org->id, $venue->org_id);
    }

    public function test_user_membership_has_a_role(): void
    {
        $org = Org::create(['name' => 'Test Org']);
        $user = User::factory()->create();

        $membership = Membership::create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $this->assertTrue($user->memberships->contains($membership));
        $this->assertEquals('owner', $membership->role);
    }

    public function test_membership_role_is_restricted_to_known_values(): void
    {
        $org = Org::create(['name' => 'Test Org']);
        $user = User::factory()->create();

        $this->expectException(\Illuminate\Database\QueryException::class);

        Membership::create([
            'org_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'superadmin', // not a valid role
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail artisan test --filter TenancyModelsTest`
Expected: FAIL — classes `Org`, `Venue`, `Membership` don't exist yet.

- [ ] **Step 3: Create the migrations**

```bash
./vendor/bin/sail artisan make:migration create_orgs_table
./vendor/bin/sail artisan make:migration create_venues_table
./vendor/bin/sail artisan make:migration create_memberships_table
```

```php
<?php
// api/database/migrations/..._create_orgs_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orgs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orgs');
    }
};
```

```php
<?php
// api/database/migrations/..._create_venues_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->constrained('orgs')->cascadeOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('timezone')->default('Australia/Perth');
            $table->string('segment'); // restaurant | cafe | bar | ...
            $table->string('cuisine')->nullable();
            $table->uuid('market_id')->nullable(); // FK added to geo_areas in Plan B
            $table->time('business_day_cutoff')->default('04:00:00');
            $table->boolean('gst_inclusive_default')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venues');
    }
};
```

```php
<?php
// api/database/migrations/..._create_memberships_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->constrained('orgs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role');
            $table->timestamps();
            $table->unique(['org_id', 'user_id']);
        });

        DB::statement("ALTER TABLE memberships ADD CONSTRAINT memberships_role_check CHECK (role IN ('owner','manager','viewer'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
```

- [ ] **Step 4: Create the models**

```php
<?php
// api/app/Models/Org.php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Org extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = ['name'];

    public function venues()
    {
        return $this->hasMany(Venue::class);
    }

    public function memberships()
    {
        return $this->hasMany(Membership::class);
    }
}
```

```php
<?php
// api/app/Models/Venue.php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Venue extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'name', 'address', 'timezone', 'segment', 'cuisine',
        'market_id', 'business_day_cutoff', 'gst_inclusive_default',
    ];

    public function org()
    {
        return $this->belongsTo(Org::class);
    }
}
```

```php
<?php
// api/app/Models/Membership.php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Membership extends Model
{
    use HasUuids;

    protected $fillable = ['org_id', 'user_id', 'role'];

    public function org()
    {
        return $this->belongsTo(Org::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
```

Modify `api/app/Models/User.php` — add the relation:

```php
public function memberships()
{
    return $this->hasMany(Membership::class);
}
```

- [ ] **Step 5: Run migrations and the test**

```bash
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan test --filter TenancyModelsTest
```

Expected: PASS (all 3 tests).

- [ ] **Step 6: Commit**

```bash
git add api/database/migrations api/app/Models api/tests/Feature/Tenancy
git commit -m "feat: add org/venue/membership tenancy model"
```

---

### Task 3: Postgres row-level security

**Files:**
- Create: `api/database/migrations/..._enable_rls_on_tenant_tables.php`
- Create: `api/app/Services/Tenancy/TenantContext.php`
- Create: `api/app/Http/Middleware/SetTenantContext.php`
- Modify: `api/bootstrap/app.php` (register middleware globally on the API group)
- Test: `api/tests/Feature/Tenancy/RowLevelSecurityTest.php`

**Interfaces:**
- Produces: `TenantContext::set(string $orgId): void`, `TenantContext::clear(): void`. Once set, every query on `venues` and `memberships` run on the current DB connection is filtered to that org at the database level, regardless of application-level `WHERE` clauses.
- Consumes: `Org`, `Venue`, `Membership` from Task 2.

- [ ] **Step 1: Write the failing test**

```php
<?php
// api/tests/Feature/Tenancy/RowLevelSecurityTest.php

namespace Tests\Feature\Tenancy;

use App\Models\Org;
use App\Models\Venue;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RowLevelSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_venues_are_isolated_by_org_at_the_database_level(): void
    {
        $orgA = Org::create(['name' => 'Org A']);
        $orgB = Org::create(['name' => 'Org B']);
        Venue::create(['org_id' => $orgA->id, 'name' => 'A Venue', 'segment' => 'cafe']);
        Venue::create(['org_id' => $orgB->id, 'name' => 'B Venue', 'segment' => 'cafe']);

        TenantContext::set($orgA->id);
        // Raw query, bypassing any Eloquent global scope, to prove the DB itself enforces isolation.
        $rows = DB::select('select name from venues');
        $this->assertCount(1, $rows);
        $this->assertEquals('A Venue', $rows[0]->name);

        TenantContext::set($orgB->id);
        $rows = DB::select('select name from venues');
        $this->assertCount(1, $rows);
        $this->assertEquals('B Venue', $rows[0]->name);
    }

    public function test_no_tenant_context_means_no_rows_visible(): void
    {
        $org = Org::create(['name' => 'Org A']);
        Venue::create(['org_id' => $org->id, 'name' => 'A Venue', 'segment' => 'cafe']);

        TenantContext::clear();
        $rows = DB::select('select name from venues');
        $this->assertCount(0, $rows, 'RLS must fail closed with no tenant context set');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail artisan test --filter RowLevelSecurityTest`
Expected: FAIL — `TenantContext` doesn't exist, or RLS isn't enabled so both rows are visible.

- [ ] **Step 3: Create the RLS migration**

RLS policies must apply to the non-owner app role, since Postgres exempts table owners from RLS by default. This migration creates a restricted `app_user` role, grants it exactly the privileges it needs, and enables RLS on `venues` and `memberships`.

```php
<?php
// api/database/migrations/..._enable_rls_on_tenant_tables.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("DO $$ BEGIN
            IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'app_user') THEN
                CREATE ROLE app_user LOGIN PASSWORD 'app_user_password';
            END IF;
        END $$;");

        DB::statement('GRANT USAGE ON SCHEMA public TO app_user');
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON venues, memberships TO app_user');
        DB::statement('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO app_user');

        DB::statement('ALTER TABLE venues ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE venues FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY venues_tenant_isolation ON venues
            USING (org_id::text = current_setting('app.current_org_id', true))
            WITH CHECK (org_id::text = current_setting('app.current_org_id', true))");

        DB::statement('ALTER TABLE memberships ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE memberships FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY memberships_tenant_isolation ON memberships
            USING (org_id::text = current_setting('app.current_org_id', true))
            WITH CHECK (org_id::text = current_setting('app.current_org_id', true))");
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS venues_tenant_isolation ON venues');
        DB::statement('ALTER TABLE venues DISABLE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS memberships_tenant_isolation ON memberships');
        DB::statement('ALTER TABLE memberships DISABLE ROW LEVEL SECURITY');
    }
};
```

Update `api/.env` (and `.env.testing` if Sail generated one) so the app connects as `app_user`, not the Postgres superuser Sail creates by default:

```
DB_USERNAME=app_user
DB_PASSWORD=app_user_password
```

Note for the executor: the migration itself runs once as the privileged Sail user (to create the role and grants), but the app's runtime connection must switch to `app_user` afterward, or RLS will silently not apply (table owners bypass RLS). Confirm with:
```sql
select current_user;
```
inside `./vendor/bin/sail artisan tinker` — it must print `app_user`, not `sail`.

- [ ] **Step 4: Implement `TenantContext`**

```php
<?php
// api/app/Services/Tenancy/TenantContext.php
namespace App\Services\Tenancy;

use Illuminate\Support\Facades\DB;

class TenantContext
{
    public static function set(string $orgId): void
    {
        DB::statement('SET app.current_org_id = ?', [$orgId]);
    }

    public static function clear(): void
    {
        DB::statement("SET app.current_org_id = ''");
    }
}
```

Note: this uses `SET` (session-scoped), which is correct for Laravel's default one-connection-per-request model (PHP-FPM/Sail). If the app later adopts Laravel Octane or any long-lived worker that reuses connections across requests, this must change to `SET LOCAL` inside an explicit transaction per request — flagged in Open Items below, not solved in this plan.

- [ ] **Step 5: Implement the middleware and register it**

```php
<?php
// api/app/Http/Middleware/SetTenantContext.php
namespace App\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

class SetTenantContext
{
    public function handle(Request $request, Closure $next)
    {
        $orgId = $request->attributes->get('current_org_id')
            ?? $request->header('X-Org-Id'); // temporary until Task 8 wires this from the session

        if ($orgId) {
            TenantContext::set($orgId);
        } else {
            TenantContext::clear();
        }

        return $next($request);
    }
}
```

Register it on the `api` middleware group in `api/bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->appendToGroup('api', \App\Http\Middleware\SetTenantContext::class);
})
```

(This is wired to a real header/session value in Task 8 once login exists; for now it only needs to satisfy the test, which calls `TenantContext::set()` directly.)

- [ ] **Step 6: Run migration and test**

```bash
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan test --filter RowLevelSecurityTest
```

Expected: PASS (both tests).

- [ ] **Step 7: Commit**

```bash
git add api/database/migrations api/app/Services/Tenancy api/app/Http/Middleware api/bootstrap/app.php api/tests/Feature/Tenancy/RowLevelSecurityTest.php
git commit -m "feat: enforce tenant isolation with Postgres row-level security"
```

---

### Task 4: Per-org envelope encryption (local KMS driver)

**Files:**
- Create: `api/app/Services/Encryption/KeyManagementService.php`
- Create: `api/app/Services/Encryption/LocalFileKmsDriver.php`
- Create: `api/app/Services/Encryption/EnvelopeEncryptor.php`
- Create: `api/config/kms.php`
- Test: `api/tests/Unit/Services/EnvelopeEncryptorTest.php`

**Interfaces:**
- Produces: `EnvelopeEncryptor::encrypt(string $orgId, string $plaintext): string` (returns a self-contained ciphertext blob), `EnvelopeEncryptor::decrypt(string $orgId, string $blob): string`, `EnvelopeEncryptor::destroyOrgKey(string $orgId): void`.
- `KeyManagementService` is an interface so a real AWS/Azure/GCP KMS driver can be swapped in later without touching call sites (05-security.md §5.2, and the provider-agnostic pattern used for the AI gateway in 03-integrations.md §3.2).

- [ ] **Step 1: Write the failing test**

```php
<?php
// api/tests/Unit/Services/EnvelopeEncryptorTest.php
namespace Tests\Unit\Services;

use App\Services\Encryption\EnvelopeEncryptor;
use App\Services\Encryption\LocalFileKmsDriver;
use Tests\TestCase;

class EnvelopeEncryptorTest extends TestCase
{
    public function test_round_trip_encrypts_and_decrypts(): void
    {
        $encryptor = new EnvelopeEncryptor(new LocalFileKmsDriver());
        $orgId = (string) \Illuminate\Support\Str::uuid();

        $blob = $encryptor->encrypt($orgId, 'super-secret-pos-token');

        $this->assertNotEquals('super-secret-pos-token', $blob);
        $this->assertEquals('super-secret-pos-token', $encryptor->decrypt($orgId, $blob));
    }

    public function test_different_orgs_get_different_keys(): void
    {
        $encryptor = new EnvelopeEncryptor(new LocalFileKmsDriver());
        $orgA = (string) \Illuminate\Support\Str::uuid();
        $orgB = (string) \Illuminate\Support\Str::uuid();

        $blob = $encryptor->encrypt($orgA, 'secret');

        $this->expectException(\RuntimeException::class);
        $encryptor->decrypt($orgB, $blob); // wrong org's key must not decrypt
    }

    public function test_destroying_an_org_key_makes_ciphertext_permanently_unreadable(): void
    {
        $encryptor = new EnvelopeEncryptor(new LocalFileKmsDriver());
        $orgId = (string) \Illuminate\Support\Str::uuid();
        $blob = $encryptor->encrypt($orgId, 'secret');

        $encryptor->destroyOrgKey($orgId);

        $this->expectException(\RuntimeException::class);
        $encryptor->decrypt($orgId, $blob);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail artisan test --filter EnvelopeEncryptorTest`
Expected: FAIL — classes don't exist.

- [ ] **Step 3: Implement the KMS interface and local driver**

```php
<?php
// api/app/Services/Encryption/KeyManagementService.php
namespace App\Services\Encryption;

interface KeyManagementService
{
    /** Returns the org's raw 32-byte data key, generating and persisting one if it doesn't exist. */
    public function getOrCreateDataKey(string $orgId): string;

    /** Permanently destroys the org's data key. Any ciphertext under it becomes unreadable forever. */
    public function destroyDataKey(string $orgId): void;
}
```

```php
<?php
// api/app/Services/Encryption/LocalFileKmsDriver.php
namespace App\Services\Encryption;

use Illuminate\Support\Facades\Storage;

/**
 * Dev/test-only KMS driver. Wraps each org's data key with a single master key
 * read from config('kms.master_key') and stores the wrapped key on the local disk.
 * NOT for production use — see the plan's Open Items for the AWS KMS driver this
 * interface exists to make swappable.
 */
class LocalFileKmsDriver implements KeyManagementService
{
    private function masterKey(): string
    {
        $key = config('kms.master_key');
        if (! $key) {
            throw new \RuntimeException('kms.master_key is not configured');
        }
        return sodium_hex2bin($key);
    }

    private function path(string $orgId): string
    {
        return "kms-keys/{$orgId}.key";
    }

    public function getOrCreateDataKey(string $orgId): string
    {
        $disk = Storage::disk('local');
        $path = $this->path($orgId);

        if ($disk->exists($path)) {
            $wrapped = base64_decode($disk->get($path));
            $nonce = substr($wrapped, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = substr($wrapped, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $key = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->masterKey());
            if ($key === false) {
                throw new \RuntimeException("Unable to unwrap data key for org {$orgId}");
            }
            return $key;
        }

        $dataKey = sodium_crypto_secretbox_keygen();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $wrapped = $nonce . sodium_crypto_secretbox($dataKey, $nonce, $this->masterKey());
        $disk->put($path, base64_encode($wrapped));

        return $dataKey;
    }

    public function destroyDataKey(string $orgId): void
    {
        Storage::disk('local')->delete($this->path($orgId));
    }
}
```

```php
<?php
// api/config/kms.php
return [
    // 64 hex chars (32 bytes). Generate with: sodium_bin2hex(sodium_crypto_secretbox_keygen())
    'master_key' => env('KMS_MASTER_KEY'),
];
```

Add to `api/.env` and `api/.env.example` (generate a real value locally — do not commit a real key):
```
KMS_MASTER_KEY=
```

- [ ] **Step 4: Implement the envelope encryptor**

```php
<?php
// api/app/Services/Encryption/EnvelopeEncryptor.php
namespace App\Services\Encryption;

class EnvelopeEncryptor
{
    public function __construct(private KeyManagementService $kms) {}

    public function encrypt(string $orgId, string $plaintext): string
    {
        $key = $this->kms->getOrCreateDataKey($orgId);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);

        return base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $orgId, string $blob): string
    {
        $key = $this->kms->getOrCreateDataKey($orgId);
        $raw = base64_decode($blob);
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed: wrong key or corrupted ciphertext');
        }
        return $plaintext;
    }

    public function destroyOrgKey(string $orgId): void
    {
        $this->kms->destroyDataKey($orgId);
    }
}
```

- [ ] **Step 5: Generate a test master key and run the test**

```bash
./vendor/bin/sail artisan tinker --execute="echo sodium_bin2hex(sodium_crypto_secretbox_keygen());"
```
Paste the output into `api/.env` (and `api/.env.testing`) as `KMS_MASTER_KEY=...`.

```bash
./vendor/bin/sail artisan test --filter EnvelopeEncryptorTest
```
Expected: PASS (all 3 tests).

- [ ] **Step 6: Commit**

```bash
git add api/app/Services/Encryption api/config/kms.php api/.env.example api/tests/Unit/Services
git commit -m "feat: add per-org envelope encryption with a local KMS driver"
```

---

### Task 5: Registration and login (Sanctum)

**Files:**
- Modify: `api/routes/api.php`
- Create: `api/app/Http/Controllers/Auth/RegisterController.php`
- Create: `api/app/Http/Controllers/Auth/LoginController.php`
- Test: `api/tests/Feature/Auth/RegistrationTest.php`
- Test: `api/tests/Feature/Auth/LoginTest.php`

**Interfaces:**
- Consumes: `Org`, `Membership` from Task 2.
- Produces: `POST /api/register` (creates an Org, a User, and an `owner` Membership), `POST /api/login`, `POST /api/logout`, all using Sanctum's cookie session (no bearer tokens for the SPA, per 05-security.md §5.3).

- [ ] **Step 1: Install Sanctum**

```bash
./vendor/bin/sail composer require laravel/sanctum
./vendor/bin/sail artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
./vendor/bin/sail artisan migrate
```

- [ ] **Step 2: Write the failing tests**

```php
<?php
// api/tests/Feature/Auth/RegistrationTest.php
namespace Tests\Feature\Auth;

use App\Models\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_creates_an_org_a_user_and_an_owner_membership(): void
    {
        $response = $this->postJson('/api/register', [
            'org_name' => 'Test Cafe Group',
            'name' => 'Jane Owner',
            'email' => 'jane@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

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
        ]);

        $response->assertStatus(422);
    }
}
```

```php
<?php
// api/tests/Feature/Auth/LoginTest.php
namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_registered_user_can_log_in(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-horse-battery-staple')]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-staple',
        ]);

        $response->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_a_wrong_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-horse-battery-staple')]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $this->assertGuest();
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `./vendor/bin/sail artisan test --filter "RegistrationTest|LoginTest"`
Expected: FAIL — routes return 404.

- [ ] **Step 4: Implement the controllers**

```php
<?php
// api/app/Http/Controllers/Auth/RegisterController.php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Org;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class RegisterController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'org_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
        ]);

        $user = DB::transaction(function () use ($data) {
            $org = Org::create(['name' => $data['org_name']]);

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => bcrypt($data['password']),
            ]);

            Membership::create([
                'org_id' => $org->id,
                'user_id' => $user->id,
                'role' => 'owner',
            ]);

            return $user;
        });

        auth()->login($user);

        return response()->json(['id' => $user->id], 201);
    }
}
```

```php
<?php
// api/app/Http/Controllers/Auth/LoginController.php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $request->session()->regenerate();

        return response()->json(['id' => Auth::id()]);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
```

Add routes in `api/routes/api.php`:
```php
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;

Route::post('/register', RegisterController::class);
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth:sanctum');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/sail artisan test --filter "RegistrationTest|LoginTest"`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add api/app/Http/Controllers/Auth api/routes/api.php api/tests/Feature/Auth api/composer.json api/composer.lock
git commit -m "feat: add registration and login with Sanctum session auth"
```

---

### Task 6: TOTP MFA, mandatory for owners

**Files:**
- Create: `api/database/migrations/..._add_mfa_fields_to_users_table.php`
- Create: `api/app/Http/Controllers/Auth/MfaController.php`
- Modify: `api/app/Http/Controllers/Auth/LoginController.php`
- Test: `api/tests/Feature/Auth/MfaTest.php`

**Interfaces:**
- Consumes: `Auth::attempt`, `User` from Task 5.
- Produces: `POST /api/mfa/enroll` (returns a TOTP secret and QR payload), `POST /api/mfa/confirm` (verifies the first code and turns MFA on), and login now returns `{"mfa_required": true}` instead of a session for owners who have MFA pending.

- [ ] **Step 1: Install the TOTP package and migrate**

```bash
./vendor/bin/sail composer require pragmarx/google2fa-laravel
./vendor/bin/sail artisan make:migration add_mfa_fields_to_users_table
```

```php
<?php
// api/database/migrations/..._add_mfa_fields_to_users_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('mfa_secret')->nullable(); // encrypted via Laravel's `encrypted` cast, not EnvelopeEncryptor (this is a platform-wide secret, not org data)
            $table->boolean('mfa_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_secret', 'mfa_enabled']);
        });
    }
};
```

- [ ] **Step 2: Write the failing test**

```php
<?php
// api/tests/Feature/Auth/MfaTest.php
namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class MfaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_enroll_and_confirm_mfa(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $enroll = $this->postJson('/api/mfa/enroll');
        $enroll->assertOk();
        $secret = $enroll->json('secret');

        $google2fa = new Google2FA();
        $validCode = $google2fa->getCurrentOtp($secret);

        $confirm = $this->postJson('/api/mfa/confirm', ['code' => $validCode]);
        $confirm->assertOk();

        $this->assertTrue($user->fresh()->mfa_enabled);
    }

    public function test_login_requires_a_second_step_when_mfa_is_enabled(): void
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        $user = User::factory()->create([
            'password' => bcrypt('correct-horse-battery-staple'),
            'mfa_secret' => $secret,
            'mfa_enabled' => true,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-staple',
        ]);

        $response->assertOk();
        $response->assertJson(['mfa_required' => true]);
        $this->assertGuest(); // not fully authenticated until the TOTP step completes
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `./vendor/bin/sail artisan test --filter MfaTest`
Expected: FAIL — routes don't exist yet.

- [ ] **Step 4: Implement the controller**

```php
<?php
// api/app/Http/Controllers/Auth/MfaController.php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use PragmaRX\Google2FA\Google2FA;

class MfaController extends Controller
{
    public function enroll(Request $request)
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        $request->user()->update(['mfa_secret' => $secret, 'mfa_enabled' => false]);

        return response()->json(['secret' => $secret]);
    }

    public function confirm(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string']]);

        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey($request->user()->mfa_secret, $data['code']);

        if (! $valid) {
            return response()->json(['message' => 'Invalid code'], 422);
        }

        $request->user()->update(['mfa_enabled' => true]);

        return response()->noContent();
    }
}
```

Update `LoginController::login` to short-circuit for MFA-enabled users:

```php
public function login(Request $request)
{
    $credentials = $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required'],
    ]);

    $user = \App\Models\User::where('email', $credentials['email'])->first();

    if (! $user || ! \Hash::check($credentials['password'], $user->password)) {
        throw ValidationException::withMessages([
            'email' => ['These credentials do not match our records.'],
        ]);
    }

    if ($user->mfa_enabled) {
        // Full session-based MFA challenge state (short-lived token) is finalized
        // when the login UI is built in Plan D; for now this proves the branch exists.
        return response()->json(['mfa_required' => true]);
    }

    Auth::login($user);
    $request->session()->regenerate();

    return response()->json(['id' => $user->id]);
}
```

Add routes to `api/routes/api.php`:
```php
use App\Http\Controllers\Auth\MfaController;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/mfa/enroll', [MfaController::class, 'enroll']);
    Route::post('/mfa/confirm', [MfaController::class, 'confirm']);
});
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/sail artisan test --filter MfaTest`
Expected: PASS (both tests).

- [ ] **Step 6: Commit**

```bash
git add api/database/migrations api/app/Http/Controllers/Auth api/tests/Feature/Auth/MfaTest.php api/composer.json api/composer.lock
git commit -m "feat: add TOTP MFA enrollment and require it at login when enabled"
```

**Note for a later task (not in this plan):** enforcing "owners must have MFA" (rather than merely offering it) needs a policy check on the owner-only routes once those exist in Plan B/C, e.g. blocking POS-connection creation until `mfa_enabled` is true for that org's owner. Track this in Open Items.

---

### Task 7: Role-based authorization policy

**Files:**
- Create: `api/app/Policies/VenuePolicy.php`
- Modify: `api/app/Providers/AppServiceProvider.php` (register the policy)
- Test: `api/tests/Feature/Tenancy/MembershipPolicyTest.php`

**Interfaces:**
- Consumes: `Membership::role` from Task 2.
- Produces: `Gate::authorize('update', $venue)` semantics: `owner` and `manager` can update a venue; `viewer` cannot. Only `owner` can delete.

- [ ] **Step 1: Write the failing test**

```php
<?php
// api/tests/Feature/Tenancy/MembershipPolicyTest.php
namespace Tests\Feature\Tenancy;

use App\Models\Membership;
use App\Models\Org;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MembershipPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function makeMember(string $role): array
    {
        $org = Org::create(['name' => 'Org']);
        $venue = Venue::create(['org_id' => $org->id, 'name' => 'V', 'segment' => 'cafe']);
        $user = User::factory()->create();
        Membership::create(['org_id' => $org->id, 'user_id' => $user->id, 'role' => $role]);

        return [$user, $venue];
    }

    public function test_owner_can_update_and_delete_a_venue(): void
    {
        [$user, $venue] = $this->makeMember('owner');
        $this->assertTrue($user->can('update', $venue));
        $this->assertTrue($user->can('delete', $venue));
    }

    public function test_manager_can_update_but_not_delete_a_venue(): void
    {
        [$user, $venue] = $this->makeMember('manager');
        $this->assertTrue($user->can('update', $venue));
        $this->assertFalse($user->can('delete', $venue));
    }

    public function test_viewer_cannot_update_or_delete_a_venue(): void
    {
        [$user, $venue] = $this->makeMember('viewer');
        $this->assertFalse($user->can('update', $venue));
        $this->assertFalse($user->can('delete', $venue));
    }

    public function test_a_user_with_no_membership_has_no_access(): void
    {
        $org = Org::create(['name' => 'Org']);
        $venue = Venue::create(['org_id' => $org->id, 'name' => 'V', 'segment' => 'cafe']);
        $user = User::factory()->create();

        $this->assertFalse($user->can('update', $venue));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail artisan test --filter MembershipPolicyTest`
Expected: FAIL — no policy registered, so `can()` returns false for everyone including the owner case, failing the first assertion.

- [ ] **Step 3: Implement the policy**

```php
<?php
// api/app/Policies/VenuePolicy.php
namespace App\Policies;

use App\Models\Membership;
use App\Models\User;
use App\Models\Venue;

class VenuePolicy
{
    private function roleFor(User $user, Venue $venue): ?string
    {
        return Membership::where('org_id', $venue->org_id)
            ->where('user_id', $user->id)
            ->value('role');
    }

    public function view(User $user, Venue $venue): bool
    {
        return $this->roleFor($user, $venue) !== null;
    }

    public function update(User $user, Venue $venue): bool
    {
        return in_array($this->roleFor($user, $venue), ['owner', 'manager'], true);
    }

    public function delete(User $user, Venue $venue): bool
    {
        return $this->roleFor($user, $venue) === 'owner';
    }
}
```

Register it in `api/app/Providers/AppServiceProvider.php`:
```php
use App\Models\Venue;
use App\Policies\VenuePolicy;
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::policy(Venue::class, VenuePolicy::class);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail artisan test --filter MembershipPolicyTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add api/app/Policies api/app/Providers/AppServiceProvider.php api/tests/Feature/Tenancy/MembershipPolicyTest.php
git commit -m "feat: add role-based venue authorization policy"
```

---

### Task 8: Append-only audit log

**Files:**
- Create: `api/database/migrations/..._create_audit_log_table.php`
- Create: `api/app/Models/AuditLogEntry.php`
- Create: `api/app/Services/Audit/AuditLogger.php`
- Modify: `api/app/Http/Controllers/Auth/LoginController.php` (log login/logout)
- Test: `api/tests/Feature/Audit/AuditLogTest.php`

**Interfaces:**
- Produces: `AuditLogger::record(string $action, string $entityType, string $entityId, ?string $orgId = null, array $meta = []): void`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// api/tests/Feature/Audit/AuditLogTest.php
namespace Tests\Feature\Audit;

use App\Models\AuditLogEntry;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_recording_an_event_writes_an_entry(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        app(AuditLogger::class)->record('login', 'user', (string) $user->id);

        $this->assertDatabaseHas('audit_log', [
            'action' => 'login',
            'entity_type' => 'user',
            'entity_id' => (string) $user->id,
            'actor_id' => $user->id,
        ]);
    }

    public function test_login_and_logout_are_audited(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-horse-battery-staple')]);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'correct-horse-battery-staple']);
        $this->assertDatabaseHas('audit_log', ['action' => 'login', 'entity_id' => (string) $user->id]);

        $this->postJson('/api/logout');
        $this->assertDatabaseHas('audit_log', ['action' => 'logout', 'entity_id' => (string) $user->id]);
    }

    public function test_audit_log_rows_cannot_be_updated_by_the_app_role(): void
    {
        $user = User::factory()->create();
        app(AuditLogger::class)->record('login', 'user', (string) $user->id);
        $entry = AuditLogEntry::first();

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::statement('update audit_log set action = ? where id = ?', ['tampered', $entry->id]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail artisan test --filter AuditLogTest`
Expected: FAIL — table and classes don't exist.

- [ ] **Step 3: Create the migration, restricting UPDATE/DELETE for `app_user`**

```php
<?php
// api/database/migrations/..._create_audit_log_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('org_id')->nullable();
            $table->string('action');
            $table->string('entity_type');
            $table->string('entity_id');
            $table->string('ip_address')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // app_user (created in Task 3's RLS migration) may only append.
        DB::statement('GRANT SELECT, INSERT ON audit_log TO app_user');
        DB::statement('REVOKE UPDATE, DELETE ON audit_log FROM app_user');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
```

- [ ] **Step 4: Implement the model and logger**

```php
<?php
// api/app/Models/AuditLogEntry.php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuditLogEntry extends Model
{
    use HasUuids;

    public $timestamps = false;
    protected $table = 'audit_log';
    protected $fillable = ['actor_id', 'org_id', 'action', 'entity_type', 'entity_id', 'ip_address', 'meta'];
    protected $casts = ['meta' => 'array'];

    protected static function booted()
    {
        static::creating(function ($entry) {
            $entry->created_at = now();
        });
    }
}
```

```php
<?php
// api/app/Services/Audit/AuditLogger.php
namespace App\Services\Audit;

use App\Models\AuditLogEntry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditLogger
{
    public function record(string $action, string $entityType, string $entityId, ?string $orgId = null, array $meta = []): void
    {
        AuditLogEntry::create([
            'actor_id' => Auth::id(),
            'org_id' => $orgId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => Request::ip(),
            'meta' => $meta,
        ]);
    }
}
```

Wire it into `LoginController`:
```php
// in login(), right before returning the mfa_required response and right before Auth::login success return:
app(\App\Services\Audit\AuditLogger::class)->record('login', 'user', (string) $user->id);

// in logout(), before Auth::logout():
app(\App\Services\Audit\AuditLogger::class)->record('logout', 'user', (string) Auth::id());
```

- [ ] **Step 5: Run migration and tests**

```bash
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan test --filter AuditLogTest
```
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add api/database/migrations api/app/Models/AuditLogEntry.php api/app/Services/Audit api/app/Http/Controllers/Auth/LoginController.php api/tests/Feature/Audit
git commit -m "feat: add append-only audit log for login/logout events"
```

---

### Task 9: Full test suite and environment sanity pass

**Files:** none created; this task only verifies.

- [ ] **Step 1: Run the complete suite**

```bash
./vendor/bin/sail artisan test
```
Expected: all tests from Tasks 2–8 pass, zero failures.

- [ ] **Step 2: Confirm the app role truly cannot bypass RLS or the audit-log restriction outside of tests**

```bash
./vendor/bin/sail artisan tinker --execute="
DB::statement(\"SET app.current_org_id = ''\");
dump(DB::select('select count(*) from venues')[0]);
"
```
Expected: `count` is `0`, confirming fail-closed behavior interactively, not just under `RefreshDatabase`.

- [ ] **Step 3: Tag the milestone**

```bash
git tag foundation-plan-complete
git log --oneline -10
```

No further commit needed — this task is verification-only.

---

## Self-Review Notes

- **Spec coverage:** orgs/venues/users/memberships (02-data-model.md §2.1) ✅ Task 2; RLS (§5.4) ✅ Task 3; envelope encryption (§5.2) ✅ Task 4; auth + MFA (§5.3) ✅ Tasks 5–6; roles (§2.1, owner/manager/viewer) ✅ Task 7; audit log (§2.6, §5.6) ✅ Task 8. Out of scope for this plan (deferred to Plans B/C/D): OIDC provider, competitor sets, geo_areas, ingestion, metrics, AI, and all UI — each has its own plan.
- **Type consistency:** `Membership::role` values (`owner|manager|viewer`) match across the DB CHECK constraint (Task 2), the policy (Task 7), and every test. `TenantContext::set/clear` signatures match their one call site (middleware) and their direct use in tests.
- **Known gap, deliberately deferred, not a placeholder:** enforcing "owners must have MFA" as a hard gate (vs. just offering enrollment) needs owner-only protected routes, which don't exist until Plan B/C. Recorded in Open Items below, not silently dropped.

## Open Items (raised by this plan, not blocking, tracked for later)

1. **AWS KMS driver.** `LocalFileKmsDriver` is dev/test only. A production `AwsKmsDriver implements KeyManagementService` is needed before Phase 5 launch readiness (05-security.md §5.2), once the AWS-region decision from `07-decisions-and-open-questions.md` #4 is made.
2. **`SET` vs `SET LOCAL` for tenant context.** Correct for Sail's default PHP-FPM model; must be revisited if Octane or any persistent-worker deployment is adopted later.
3. **Hard MFA enforcement for owners.** Currently offered, not yet blocking. Add an `EnsureOwnerHasMfa` middleware/policy check once owner-only routes exist (Plan B onward).
4. **RLS coverage will grow.** Every new tenant-owned table added in Plans B–D (competitor_sets, pos_connections, sales_daily, etc.) must get its own RLS policy following the Task 3 pattern — this is a per-table checklist item for those plans, not automatic.
