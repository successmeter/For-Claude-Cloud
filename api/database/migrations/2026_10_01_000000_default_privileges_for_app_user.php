<?php
// api/database/migrations/2026_10_01_000000_default_privileges_for_app_user.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan A Open Item 4: grant app_user on every future table automatically instead of by a per-table
 * list (which already missed personal_access_tokens once).
 *
 * Default privileges apply to objects created later BY THE NAMED ROLE. Migrations always run on the
 * privileged `pgsql` connection (Plan A), so that connection's current_user is the role to name.
 *
 * Deliberately SELECT, INSERT, UPDATE only -- never DELETE. Postgres runs cascading FK actions with
 * row security disabled, so DELETE on a parent table can reach other orgs' rows. A table that needs
 * DELETE gets an explicit grant with a comment, and must be added to
 * SchemaSecurityCatalogTest::ALLOWED_DELETE.
 */
return new class extends Migration
{
    public function up(): void
    {
        $owner = $this->owner();
        DB::connection('pgsql')->statement("ALTER DEFAULT PRIVILEGES FOR ROLE \"{$owner}\" IN SCHEMA public GRANT SELECT, INSERT, UPDATE ON TABLES TO app_user");
        DB::connection('pgsql')->statement("ALTER DEFAULT PRIVILEGES FOR ROLE \"{$owner}\" IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO app_user");
    }

    public function down(): void
    {
        $owner = $this->owner();
        DB::connection('pgsql')->statement("ALTER DEFAULT PRIVILEGES FOR ROLE \"{$owner}\" IN SCHEMA public REVOKE SELECT, INSERT, UPDATE ON TABLES FROM app_user");
        DB::connection('pgsql')->statement("ALTER DEFAULT PRIVILEGES FOR ROLE \"{$owner}\" IN SCHEMA public REVOKE USAGE, SELECT ON SEQUENCES FROM app_user");
    }

    private function owner(): string
    {
        return DB::connection('pgsql')->selectOne('SELECT current_user AS u')->u;
    }
};
