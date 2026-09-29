<?php
// api/tests/Feature/Tenancy/SetTenantContextTest.php

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\SetTenantContext;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\TestCase;

class SetTenantContextTest extends TestCase
{
    use RefreshesPrivilegedDatabase;

    public function test_it_sets_tenant_context_from_the_x_org_id_header_for_the_request_only(): void
    {
        // Tests run in the `testing` environment, so SetTenantContext's local/testing
        // guard (see SetTenantContext::handle()'s own comment) permits the X-Org-Id
        // header path here.
        $this->assertTrue(app()->environment('testing'));

        $orgId = (string) \Illuminate\Support\Str::uuid();

        $request = Request::create('/api/anything', 'GET');
        $request->headers->set('X-Org-Id', $orgId);

        $seenInside = null;
        $response = (new SetTenantContext())->handle($request, function () use (&$seenInside) {
            $seenInside = TenantContext::current();

            return response('ok');
        });

        $this->assertSame('ok', $response->getContent());
        $this->assertSame($orgId, $seenInside);
        // Transaction-local since Plan B Task 1: nothing survives the request.
        $this->assertNull(TenantContext::current());
    }

    public function test_it_leaves_no_tenant_context_when_no_org_id_is_present(): void
    {
        (new SetTenantContext())->handle(Request::create('/api/anything', 'GET'), fn () => response('ok'));

        $this->assertNull(TenantContext::current());
    }
}
