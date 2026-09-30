<?php
// api/tests/Feature/Tenancy/TenantContextScopeTest.php
namespace Tests\Feature\Tenancy;

use App\Models\Org;
use App\Models\Venue;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\TestCase;

class TenantContextScopeTest extends TestCase
{
    use RefreshesPrivilegedDatabase;

    public function test_run_scopes_context_and_restores_previous_value(): void
    {
        $a = Org::create(['name' => 'A']);
        $b = Org::create(['name' => 'B']);
        TenantContext::run($a->id, fn () => Venue::create(['org_id' => $a->id, 'name' => 'VA', 'segment' => 'cafe']));

        $this->assertNull(TenantContext::current());
        $seen = TenantContext::run($a->id, fn () => DB::select('select name from venues'));
        $this->assertSame(['VA'], array_column($seen, 'name'));

        TenantContext::run($b->id, function () use ($a, $b) {
            TenantContext::run($a->id, fn () => null);
            $this->assertSame($b->id, TenantContext::current());
        });
    }

    public function test_exception_inside_run_leaves_no_context_behind(): void
    {
        $a = Org::create(['name' => 'A']);
        try {
            TenantContext::run($a->id, fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }
        $this->assertNull(TenantContext::current());
    }
}
