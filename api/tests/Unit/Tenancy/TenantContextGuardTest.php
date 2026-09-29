<?php
// api/tests/Unit/Tenancy/TenantContextGuardTest.php
namespace Tests\Unit\Tenancy;

use App\Services\Tenancy\TenantContext;
use LogicException;
use Tests\TestCase;

class TenantContextGuardTest extends TestCase
{
    public function test_set_outside_a_transaction_is_refused(): void
    {
        $this->expectException(LogicException::class);
        TenantContext::set('00000000-0000-0000-0000-000000000001');
    }
}
