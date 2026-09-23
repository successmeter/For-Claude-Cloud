<?php
// api/app/Services/Tenancy/TenantContext.php
namespace App\Services\Tenancy;

use Illuminate\Support\Facades\DB;

class TenantContext
{
    public static function set(string $orgId): void
    {
        // Postgres's SET command doesn't accept bound parameters ("SET x = $1" is a syntax
        // error) — set_config() is the parameterizable equivalent. The third argument
        // (is_local => false) makes this session-scoped, matching SET's semantics.
        DB::statement("SELECT set_config('app.current_org_id', ?, false)", [$orgId]);
    }

    public static function clear(): void
    {
        DB::statement("SELECT set_config('app.current_org_id', '', false)");
    }
}
