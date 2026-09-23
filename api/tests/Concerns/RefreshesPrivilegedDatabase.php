<?php
// api/tests/Concerns/RefreshesPrivilegedDatabase.php
namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Drop-in replacement for Illuminate\Foundation\Testing\RefreshDatabase.
 *
 * The app's default DB connection (pgsql_app) authenticates as the restricted,
 * non-superuser `app_user` role (see Plan A Task 3), which deliberately cannot run
 * DDL — that's the whole point of row-level security actually applying instead of
 * being silently bypassed by a table owner/superuser. RefreshDatabase's one-time
 * schema-building `migrate:fresh` must therefore run against the privileged `pgsql`
 * connection instead of the app's default connection, while every other query a
 * test makes (model factories, raw DB::select, TenantContext::set) must still go
 * through the untouched default connection (`pgsql_app`/app_user), or RLS
 * enforcement wouldn't be exercised for real.
 *
 * This can't be solved by overriding migrateFreshUsing() on the shared Tests\TestCase
 * base class: PHP resolves a trait method inserted directly into a class (here, via
 * `use RefreshDatabase;` in the test class) ahead of an inherited method of the same
 * name from a parent class, so a plain TestCase::migrateFreshUsing() override is
 * silently shadowed and never runs. Renaming RefreshDatabase's own copy via `as` and
 * wrapping it in a method declared directly in this trait avoids that shadowing.
 *
 * Usage: `use Tests\Concerns\RefreshesPrivilegedDatabase;` in place of
 * `use Illuminate\Foundation\Testing\RefreshDatabase;`.
 */
trait RefreshesPrivilegedDatabase
{
    use RefreshDatabase {
        migrateFreshUsing as private baseMigrateFreshUsing;
    }

    protected function migrateFreshUsing()
    {
        return array_merge($this->baseMigrateFreshUsing(), [
            '--database' => 'pgsql',
        ]);
    }
}
