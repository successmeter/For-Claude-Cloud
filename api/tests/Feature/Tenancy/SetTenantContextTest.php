<?php
// api/tests/Feature/Tenancy/SetTenantContextTest.php

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\SetTenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\TestCase;

class SetTenantContextTest extends TestCase
{
    use RefreshesPrivilegedDatabase;

    public function test_it_sets_tenant_context_from_the_x_org_id_header(): void
    {
        // Tests run in the `testing` environment, so SetTenantContext's local/testing
        // guard (see Important finding #3) permits the X-Org-Id header path here.
        $this->assertTrue(app()->environment('testing'));

        $orgId = (string) \Illuminate\Support\Str::uuid();

        $request = Request::create('/api/anything', 'GET');
        $request->headers->set('X-Org-Id', $orgId);

        $middleware = new SetTenantContext();

        $response = $middleware->handle($request, function ($req) {
            return response('ok');
        });

        $this->assertSame('ok', $response->getContent());

        $current = DB::selectOne("select current_setting('app.current_org_id', true) as org_id")->org_id;
        $this->assertSame($orgId, $current);
    }

    public function test_it_clears_tenant_context_when_no_org_id_is_present(): void
    {
        $request = Request::create('/api/anything', 'GET');

        $middleware = new SetTenantContext();

        $middleware->handle($request, function ($req) {
            return response('ok');
        });

        $current = DB::selectOne("select current_setting('app.current_org_id', true) as org_id")->org_id;
        $this->assertSame('', $current);
    }
}
