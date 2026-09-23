<?php
// api/database/migrations/2026_09_24_100100_grant_app_user_on_personal_access_tokens.php
//
// IMPORTANT finding #4 (final whole-branch review): the 120000 RLS migration only
// granted app_user access to the tables that existed at that moment. Task 5's
// personal_access_tokens table (Sanctum) was added afterward (150102) and nothing
// ever granted app_user access to it. Concretely: any request with an
// `Authorization: Bearer <token>` header to a Sanctum-guarded route makes the guard
// fall through to a token lookup against this table, hits
// SQLSTATE[42501] permission denied, and surfaces as an unauthenticated 500 instead
// of a clean 401.
//
// No RLS policy is added here: personal_access_tokens is not org-scoped tenant data
// (it has no org_id column, and a token belongs to a user, not a venue/org), the
// same reasoning the 120000 migration already applied to `users`.
//
// Note on ALTER DEFAULT PRIVILEGES (raised by the same finding as an alternative to
// a per-table grant list): deliberately NOT added here. `ALTER DEFAULT PRIVILEGES
// ... GRANT ... ON TABLES TO app_user` would apply to every future table created by
// the role that issues the ALTER (not retroactively to this one, so it wouldn't
// even fix personal_access_tokens by itself), including future TENANT tables
// Plan B-D will add. Those tables need an explicit RLS policy before they hold real
// data (Global Constraints, this plan) -- RLS is off by default on a new table, so a
// blanket default grant would hand app_user full CRUD on a brand-new tenant table
// the moment it's created, before anyone remembers to add its RLS policy, silently
// widening the "forgot the policy" blast radius instead of shrinking it. A per-table
// grant, added in the same migration that creates (or enables RLS on) each table, is
// the safer default until/unless a later plan deliberately re-evaluates this
// tradeoff (tracked in the plan's Open Items #4).
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON personal_access_tokens TO app_user');
    }

    public function down(): void
    {
        DB::statement('REVOKE SELECT, INSERT, UPDATE, DELETE ON personal_access_tokens FROM app_user');
    }
};
