<?php
// api/app/Bench/RecomputeVenue.php
namespace App\Bench;

use App\Services\Tenancy\TenantContext;
use App\Strategy\Insights\InsightEngine;
use Carbon\CarbonImmutable;

/**
 * After a venue's sales change: metrics from the earliest changed day, then insights for its latest
 * day. Plan C's upload commit calls this in its own transaction, so the overview never shows new
 * sales with old metrics; POS sync (Phase 2) will use RecomputeVenueJob.
 */
class RecomputeVenue
{
    public function __construct(private DailyMetricsBuilder $metrics, private InsightEngine $insights) {}

    public function run(string $venueId, CarbonImmutable $earliestChanged): void
    {
        if (TenantContext::current() === null) {
            throw new \LogicException('RecomputeVenue runs inside tenant context.');
        }

        $this->metrics->rebuild($venueId, $earliestChanged);
        $this->insights->generate($venueId);
    }
}
