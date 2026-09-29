<?php
// api/tests/Feature/Bench/RecomputeVenueTest.php

namespace Tests\Feature\Bench;

use App\Bench\RecomputeVenue;
use App\Bench\RecomputeVenueJob;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\SalesFixtures;
use Tests\Support\SpaRequests;
use Tests\TestCase;

class RecomputeVenueTest extends TestCase
{
    use RefreshesPrivilegedDatabase, SalesFixtures, SpaRequests;

    private Venue $venue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->venue = $this->venue($this->org());
        $this->putSales($this->venue, $this->series('2026-01-01', '2026-03-31', fn () => 1000));
    }

    private function counts(): array
    {
        return $this->inTenantOf($this->venue, fn () => [
            DB::table('daily_venue_metrics')->count(),
            DB::table('insights')->value('as_of'),
        ]);
    }

    public function test_it_rebuilds_metrics_then_insights_for_the_latest_day(): void
    {
        $this->inTenantOf($this->venue, fn () => app(RecomputeVenue::class)->run($this->venue->id, CarbonImmutable::parse('2026-01-01')));

        $this->assertSame([90, '2026-03-31'], $this->counts());
    }

    public function test_it_refuses_to_run_without_tenant_context(): void
    {
        $this->expectException(\LogicException::class);
        app(RecomputeVenue::class)->run($this->venue->id, CarbonImmutable::parse('2026-01-01'));
    }

    public function test_the_job_runs_in_the_venues_org(): void
    {
        RecomputeVenueJob::dispatch($this->venue->org_id, $this->venue->id, '2026-01-01');

        $this->assertSame([90, '2026-03-31'], $this->counts());
    }
}
