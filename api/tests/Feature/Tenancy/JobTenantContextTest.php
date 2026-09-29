<?php
// api/tests/Feature/Tenancy/JobTenantContextTest.php

namespace Tests\Feature\Tenancy;

use App\Jobs\Contracts\TenantScopedJob;
use App\Jobs\Middleware\RunsWithTenantContext;
use App\Models\Org;
use App\Models\Venue;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\TestCase;

class CountVenuesForOrgJob implements ShouldQueue, TenantScopedJob
{
    use Dispatchable, Queueable;

    public static ?int $seen = null;

    public function __construct(private string $orgId) {}

    public function orgId(): string
    {
        return $this->orgId;
    }

    public function middleware(): array
    {
        return [new RunsWithTenantContext];
    }

    public function handle(): void
    {
        self::$seen = DB::table('venues')->count();
    }
}

class CountVenuesWithoutContextJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public static ?int $seen = null;

    public function handle(): void
    {
        self::$seen = DB::table('venues')->count();
    }
}

class MisconfiguredJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function middleware(): array
    {
        return [new RunsWithTenantContext];
    }

    public function handle(): void {}
}

class JobTenantContextTest extends TestCase
{
    use RefreshesPrivilegedDatabase;

    public function test_tenant_scoped_job_sees_only_its_org_and_plain_job_sees_nothing(): void
    {
        $a = Org::create(['name' => 'A']);
        $b = Org::create(['name' => 'B']);
        TenantContext::run($a->id, fn () => Venue::create(['org_id' => $a->id, 'name' => 'VA', 'segment' => 'cafe']));
        TenantContext::run($b->id, fn () => Venue::create(['org_id' => $b->id, 'name' => 'VB', 'segment' => 'cafe']));

        CountVenuesForOrgJob::dispatchSync($a->id);
        CountVenuesWithoutContextJob::dispatchSync();

        $this->assertSame(1, CountVenuesForOrgJob::$seen);
        $this->assertSame(0, CountVenuesWithoutContextJob::$seen, 'a job without tenant context must fail closed');
        $this->assertNull(TenantContext::current(), 'job context must not outlive the job');
    }

    public function test_middleware_on_a_job_without_org_id_is_a_programming_error(): void
    {
        $this->expectException(LogicException::class);

        MisconfiguredJob::dispatchSync();
    }
}
