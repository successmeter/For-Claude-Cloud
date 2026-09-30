<?php
// api/tests/Feature/Strategy/InsightEngineTest.php

namespace Tests\Feature\Strategy;

use App\Bench\DailyMetricsBuilder;
use App\Models\Venue;
use App\Strategy\Insights\InsightEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\AssertsFindingsSchema;
use Tests\Support\SalesFixtures;
use Tests\Support\SpaRequests;
use Tests\TestCase;

class InsightEngineTest extends TestCase
{
    use AssertsFindingsSchema, RefreshesPrivilegedDatabase, SalesFixtures, SpaRequests;

    private Venue $venue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->venue = $this->venue($this->org());
    }

    /** Two years with a weekly shape, 10% growth this year, a spike and a gap near the end. */
    private function load(array $overrides = []): void
    {
        $days = $this->series('2024-07-01', '2026-06-28', function (CarbonImmutable $d) {
            $base = $d->dayOfWeekIso >= 5 ? 2000 : 1000;

            return $d->gte(CarbonImmutable::parse('2025-06-30')) ? (int) round($base * 1.1) : $base;
        });
        $days['2026-06-20'] = 9000;
        unset($days['2026-06-10']);
        $this->putSales($this->venue, array_merge($days, $overrides));
        $this->inTenantOf($this->venue, fn () => app(DailyMetricsBuilder::class)->rebuild($this->venue->id, CarbonImmutable::parse('2000-01-01')));
    }

    private function generate(?string $asOf = null): ?array
    {
        return $this->inTenantOf($this->venue, fn () => app(InsightEngine::class)
            ->generate($this->venue->id, $asOf === null ? null : CarbonImmutable::parse($asOf)));
    }

    public function test_findings_are_numbered_in_a_fixed_order_and_match_the_schema(): void
    {
        $this->load();

        $doc = $this->generate();

        $this->assertMatchesFindingsSchema($doc);
        $this->assertSame('2026-06-28', $doc['as_of']);
        $this->assertSame(1, $doc['rules_version']);
        $types = array_column($doc['findings'], 'type');
        $this->assertSame(['anomaly_day', 'best_worst_weekday', 'data_gap'], $types,
            'no trends: the gap on 2026-06-10 leaves the current 28-day window incomplete');
        $this->assertSame(['f1', 'f2', 'f3'], array_column($doc['findings'], 'id'));
        $anomaly = $doc['findings'][0];
        $this->assertSame(['2026-06-20', 'high'], [$anomaly['period']['from'], $anomaly['direction']]);
    }

    public function test_it_is_stored_once_per_day_and_reads_back(): void
    {
        $this->load();

        $first = $this->generate();
        $again = $this->generate();

        $this->assertSame($first['findings_hash'], $again['findings_hash']);
        $rows = $this->inTenantOf($this->venue, fn () => DB::table('insights')->where('venue_id', $this->venue->id)->get());
        $this->assertCount(1, $rows);
        // Stored content (jsonb has its own key order) hashes to the stored and returned hash.
        $this->assertSame($first['findings_hash'], hash('sha256', InsightEngine::canonical(json_decode($rows[0]->findings, true))));
        $this->assertSame($first['findings_hash'], $rows[0]->findings_hash);
        $this->assertSame($first, $this->inTenantOf($this->venue, fn () => app(InsightEngine::class)->latest($this->venue->id)));
    }

    public function test_a_changed_day_changes_the_hash(): void
    {
        $this->load();
        $before = $this->generate()['findings_hash'];

        $this->load(['2026-06-20' => 9500]);

        $this->assertNotSame($before, $this->generate()['findings_hash']);
    }

    public function test_full_windows_give_both_trends(): void
    {
        $this->load(['2026-06-10' => 1100]);

        $types = array_column($this->generate()['findings'], 'type');

        $this->assertSame(['trend_28d_yoy', 'trend_28d_vs_prior', 'anomaly_day', 'best_worst_weekday'], $types);
        $yoy = $this->generate()['findings'][0];
        $this->assertSame('up', $yoy['direction']);
        $this->assertTrue($yoy['material']);
    }

    public function test_as_of_an_earlier_day(): void
    {
        $this->load();

        $this->assertSame('2026-05-31', $this->generate('2026-05-31')['as_of']);
        $this->assertNotContains('anomaly_day', array_column($this->generate('2026-05-31')['findings'], 'type'));
    }

    public function test_no_data_means_no_insights(): void
    {
        $this->assertNull($this->generate());
        $this->assertSame(
            ['as_of' => null, 'rules_version' => null, 'findings_hash' => null, 'findings' => []],
            $this->inTenantOf($this->venue, fn () => app(InsightEngine::class)->latest($this->venue->id)),
        );
    }
}
