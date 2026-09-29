<?php
// api/tests/Unit/Tenancy/SetTenantContextOutsideTransactionTest.php
namespace Tests\Unit\Tenancy;

use App\Http\Middleware\SetTenantContext;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * No RefreshDatabase here on purpose: production requests do not run inside a transaction, and
 * TenantContext::set()/clear() refuse to run outside one. RefreshDatabase's wrapping transaction
 * would hide exactly that failure.
 */
class SetTenantContextOutsideTransactionTest extends TestCase
{
    public function test_a_request_without_org_does_not_touch_tenant_context(): void
    {
        $response = (new SetTenantContext())->handle(
            Request::create('/api/anything', 'GET'),
            fn () => response('ok'),
        );

        $this->assertSame('ok', $response->getContent());
    }
}
