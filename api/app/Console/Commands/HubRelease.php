<?php
// api/app/Console/Commands/HubRelease.php
namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * What every deploy runs before the new code serves traffic: migrations as the database owner
 * (never as app_user, which cannot run them), then app_user's password and RLS check. The deploy
 * command on Laravel Cloud; the AWS migrate task does the same two steps.
 */
class HubRelease extends Command
{
    protected $signature = 'hub:release';

    protected $description = 'Run migrations as the owner, then apply and verify the app_user role';

    public function handle(): int
    {
        $migrate = $this->call('migrate', ['--database' => 'pgsql', '--force' => true]);
        if ($migrate !== self::SUCCESS) {
            return $migrate;
        }

        return $this->call('db:sync-app-role');
    }
}
