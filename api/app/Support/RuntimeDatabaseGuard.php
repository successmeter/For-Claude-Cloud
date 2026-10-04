<?php
// api/app/Support/RuntimeDatabaseGuard.php
namespace App\Support;

/**
 * Deployed, the Hub must run as app_user (pgsql_app), never as the database owner: the owner, or a
 * role with BYPASSRLS, is not held to row-level security. Checked at boot outside local and tests,
 * from configuration alone, so a misconfigured host fails loudly before serving anything.
 */
final class RuntimeDatabaseGuard
{
    /** @param array{default: string, connections: array} $database config('database') */
    public static function problem(array $database): ?string
    {
        if ($database['default'] !== 'pgsql_app') {
            return 'The default database connection must be pgsql_app (set HUB_DB_CONNECTION=pgsql_app).';
        }
        $app = $database['connections']['pgsql_app'] ?? [];
        $owner = $database['connections']['pgsql'] ?? [];
        if (($app['username'] ?? null) === ($owner['username'] ?? null)) {
            return 'pgsql_app connects as the database owner; set DB_APP_USERNAME=app_user and DB_APP_PASSWORD.';
        }
        if (($app['username'] ?? null) !== 'app_user') {
            return 'pgsql_app must connect as app_user (DB_APP_USERNAME).';
        }

        return null;
    }
}
