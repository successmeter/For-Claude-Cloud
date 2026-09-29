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
     * on a pooled or persistent connection (Plan A Open Item 2: queue workers, PgBouncer, Octane). On success
     * the previous value is restored explicitly, because a RELEASEd savepoint would otherwise keep it until the
     * outer transaction ends. On an exception the savepoint rollback restores it.
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
        // Postgres's SET command doesn't accept bound parameters ("SET x = $1" is a syntax
        // error) -- set_config() is the parameterizable equivalent. is_local => true ends
        // the setting with the current transaction.
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
