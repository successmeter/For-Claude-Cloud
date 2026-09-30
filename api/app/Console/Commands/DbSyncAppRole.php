<?php
// api/app/Console/Commands/DbSyncAppRole.php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sets app_user's password to the runtime connection's (DB_APP_PASSWORD) and re-checks that the
 * role cannot bypass RLS. Run by the deploy's migrate step, so rotating the secret in the secrets
 * manager and redeploying is the whole rotation. Uses the privileged connection.
 */
class DbSyncAppRole extends Command
{
    protected $signature = 'db:sync-app-role';

    protected $description = 'Apply DB_APP_PASSWORD to the app_user role and verify it cannot bypass RLS';

    public function handle(): int
    {
        $db = DB::connection('pgsql');
        $role = $db->selectOne("SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = 'app_user'");
        if ($role === null) {
            $this->error('app_user does not exist; run the migrations first.');

            return self::FAILURE;
        }
        if ($role->rolsuper || $role->rolbypassrls) {
            $this->error('app_user is a superuser or bypasses RLS.');

            return self::FAILURE;
        }

        $password = (string) config('database.connections.pgsql_app.password');
        if ($password === '') {
            $this->error('DB_APP_PASSWORD is empty.');

            return self::FAILURE;
        }
        $db->statement('ALTER ROLE app_user PASSWORD '.$db->getPdo()->quote($password));
        $this->info('app_user password applied.');

        return self::SUCCESS;
    }
}
