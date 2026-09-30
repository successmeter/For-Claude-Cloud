<?php
// api/tests/Feature/Venues/InsightsEndpointTest.php

namespace Tests\Feature\Venues;

use App\Bench\RecomputeVenue;
use App\Models\Org;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\AssertsFindingsSchema;
use Tests\Support\SalesFixtures;
use Tests\Support\SpaRequests;
use Tests\TestCase;

class InsightsEndpointTest extends TestCase
{
    use AssertsFindingsSchema, RefreshesPrivilegedDatabase, SalesFixtures, SpaRequests;

    private Org $org;

    private Venue $venue;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->org();
        $this->venue = $this->venue($this->org);
        $this->viewer = $this->member($this->org, 'viewer');
    }

    public function test_the_latest_findings_match_the_schema(): void
    {
        $this->putSales($this->venue, $this->series('2025-01-01', '2026-03-31', fn (CarbonImmutable $d) => $d->toDateString() === '2026-03-21' ? 9000 : 1000 + 100 * $d->dayOfWeekIso));
        $this->inTenantOf($this->venue, fn () => app(RecomputeVenue::class)->run($this->venue->id, CarbonImmutable::parse('2000-01-01')));

        $response = $this->spa($this->viewer, $this->org, 'GET', "/api/venues/{$this->venue->id}/insights/latest")->assertOk();

        $this->assertMatchesFindingsSchema($response->getContent());
        $this->assertSame('2026-03-31', $response->json('as_of'));
        $this->assertContains('anomaly_day', array_column($response->json('findings'), 'type'));
    }

    public function test_no_insights_yet(): void
    {
        $response = $this->spa($this->viewer, $this->org, 'GET', "/api/venues/{$this->venue->id}/insights/latest")
            ->assertOk()
            ->assertExactJson(['as_of' => null, 'rules_version' => null, 'findings_hash' => null, 'findings' => []]);

        $this->assertMatchesFindingsSchema($response->getContent());
    }

    public function test_another_orgs_venue_is_not_found(): void
    {
        $other = $this->venue($this->org('Other'));

        $this->spa($this->viewer, $this->org, 'GET', "/api/venues/{$other->id}/insights/latest")->assertNotFound();
    }
}
