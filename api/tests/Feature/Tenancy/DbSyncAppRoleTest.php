<?php
// api/tests/Feature/Tenancy/DbSyncAppRoleTest.php

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DbSyncAppRoleTest extends TestCase
{
    public function test_it_applies_the_configured_password(): void
    {
        $current = (string) config('database.connections.pgsql_app.password');

        $this->artisan('db:sync-app-role')->assertSuccessful()->expectsOutput('app_user password applied.');

        // The app connection (app_user) still connects with the configured password.
        DB::connection('pgsql_app')->reconnect();
        $this->assertSame('app_user', DB::connection('pgsql_app')->selectOne('SELECT current_user AS u')->u);
        $this->assertNotSame('', $current);
    }

    public function test_an_empty_password_is_refused(): void
    {
        config(['database.connections.pgsql_app.password' => '']);

        $this->artisan('db:sync-app-role')->assertFailed();
    }
}
