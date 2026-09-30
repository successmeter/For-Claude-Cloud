<?php
// api/tests/Feature/Uploads/SalesJourneyTest.php

namespace Tests\Feature\Uploads;

use App\Models\Org;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\AssertsFindingsSchema;
use Tests\Support\SpaRequests;
use Tests\TestCase;

/**
 * Plan C end to end through the API: create a venue, inspect, upload, commit, read the overview,
 * metrics and insights; then correct two days; and a viewer's view of it all.
 */
class SalesJourneyTest extends TestCase
{
    use AssertsFindingsSchema, RefreshesPrivilegedDatabase, SpaRequests;

    private Org $org;

    private User $owner;

    private const GAP = '2025-11-11';

    private const SPIKE = '2026-06-20';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('snapshots');
        $this->travelTo(CarbonImmutable::parse('2026-07-01 09:00', 'Australia/Perth'));
        $this->org = $this->org('Leederville Group');
        $this->owner = $this->member($this->org, 'owner');
    }

    /**
     * Two years of a POS-style export (day-first dates, dollar amounts, an extra column): weekends
     * at twice weekdays, 10% up from July 2025, one missing day and one spike.
     */
    private function export(array $overrides = []): string
    {
        $lines = ['Date,Net Sales,Transactions,Staff Notes'];
        for ($d = CarbonImmutable::parse('2024-07-01'); $d->lte(CarbonImmutable::parse('2026-06-30')); $d = $d->addDay()) {
            $date = $d->toDateString();
            if ($date === self::GAP) {
                continue;
            }
            $cents = ($d->dayOfWeekIso >= 5 ? 200000 : 100000) * ($d->gte(CarbonImmutable::parse('2025-07-01')) ? 11 : 10) / 10;
            $cents = $overrides[$date] ?? ($date === self::SPIKE ? $cents * 4 : $cents);
            $lines[] = sprintf('%s,"$%s",%d,Shift lead: Sam', $d->format('d/m/Y'), number_format($cents / 100, 2), intdiv($cents, 2500));
        }

        return implode("\r\n", $lines)."\r\n";
    }

    private function postAs(User $user, string $uri, array $data)
    {
        return $this->actingAs($user)->post($uri, $data, ['X-Hub-Org' => $this->org->id, 'Accept' => 'application/json']);
    }

    private function file(string $csv): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('pos-export.csv', $csv);
    }

    public function test_upload_to_insights_and_a_correction(): void
    {
        // A venue, created through the API.
        $venueId = $this->spa($this->owner, $this->org, 'POST', '/api/venues', ['name' => 'Oxford St Cafe', 'segment' => 'cafe'])
            ->assertCreated()->json('id');

        // Inspect proposes the mapping; the upload uses it as confirmed.
        $proposal = $this->postAs($this->owner, "/api/venues/{$venueId}/uploads/inspect", ['file' => $this->file($this->export())])
            ->assertOk()->json('proposal');
        $this->assertSame(['Date', 'DD/MM/YYYY', false, 'Net Sales', 'Transactions', true], [
            $proposal['date_column'], $proposal['date_format'], $proposal['date_ambiguous'],
            $proposal['revenue_column'], $proposal['tx_count_column'], $proposal['gst_inclusive'],
        ]);
        $mapping = array_intersect_key($proposal, array_flip(['date_column', 'date_format', 'revenue_column', 'tx_count_column'])) + ['gst_inclusive' => '1'];

        $run = $this->postAs($this->owner, "/api/venues/{$venueId}/uploads", ['file' => $this->file($this->export())] + $mapping)->assertCreated();
        $run->assertJson(['summary' => ['rows' => 729, 'new' => 729, 'changed' => 0, 'problems' => 0, 'first_date' => '2024-07-01', 'last_date' => '2026-06-30']]);
        $this->spa($this->owner, $this->org, 'POST', "/api/uploads/{$run->json('id')}/commit")->assertOk()->assertJsonPath('status', 'committed');

        // Overview.
        $overview = $this->spa($this->owner, $this->org, 'GET', "/api/venues/{$venueId}/overview")->assertOk();
        $overview->assertJson([
            // 364 days back is 2025-07-01, the first day of the uplift: flat on the same weekday.
            'latest' => ['date' => '2026-06-30', 'revenue_cents' => 110000, 'last_week_cents' => 110000, 'last_year_cents' => 110000, 'yoy_pct' => 0.0],
            'freshness' => ['status' => 'fresh', 'days_behind' => 1],
        ]);
        $this->assertSame([self::SPIKE], array_map(fn ($a) => $a['period']['from'], $overview->json('anomalies')));
        $rolling28Before = $overview->json('totals.rolling_28');

        // Metrics at every grain; the missing day stays missing.
        $days = $this->spa($this->owner, $this->org, 'GET', "/api/venues/{$venueId}/metrics?grain=day&from=2025-11-10&to=2025-11-12")->assertOk();
        $this->assertSame([110000, null, 110000], array_column($days->json('data'), 'revenue_cents'));
        $this->spa($this->owner, $this->org, 'GET', "/api/venues/{$venueId}/metrics?grain=week")->assertOk();
        $months = $this->spa($this->owner, $this->org, 'GET', "/api/venues/{$venueId}/metrics?grain=month&from=2025-11-01&to=2025-12-31")->assertOk();
        $this->assertSame([[29, 30, null], [31, 31]], [
            [$months->json('data.0.days_with_data'), $months->json('data.0.days_in_period'), $months->json('data.0.change_pct')],
            [$months->json('data.1.days_with_data'), $months->json('data.1.days_in_period')],
        ]);

        // Insights: the year-on-year trend and the spike.
        $insights = $this->spa($this->owner, $this->org, 'GET', "/api/venues/{$venueId}/insights/latest")->assertOk();
        $this->assertMatchesFindingsSchema($insights->getContent());
        $byType = collect($insights->json('findings'))->groupBy('type');
        $this->assertSame(['up', true], [$byType['trend_28d_yoy'][0]['direction'], $byType['trend_28d_yoy'][0]['material']]);
        $this->assertSame(self::SPIKE, $byType['anomaly_day'][0]['period']['from']);
        $hashBefore = $insights->json('findings_hash');
        $decemberBefore = $this->spa($this->owner, $this->org, 'GET', "/api/venues/{$venueId}/metrics?grain=day&from=2025-12-01&to=2025-12-01")->json('data');

        // A corrected export: the spike was a typo, and one more day changes.
        $corrected = $this->export([self::SPIKE => 220000, '2026-06-21' => 230000]);
        $fix = $this->postAs($this->owner, "/api/venues/{$venueId}/uploads", ['file' => $this->file($corrected)] + $mapping)->assertCreated();
        $fix->assertJson(['summary' => ['new' => 0, 'changed' => 2, 'unchanged' => 727]]);
        $changes = $this->spa($this->owner, $this->org, 'GET', "/api/uploads/{$fix->json('id')}/changes")->assertOk();
        $this->assertSame([[self::SPIKE, 880000, 220000], ['2026-06-21', 220000, 230000]],
            array_map(fn ($c) => [$c['date'], $c['old']['revenue_cents'], $c['new']['revenue_cents']], $changes->json('data')));
        $this->spa($this->owner, $this->org, 'POST', "/api/uploads/{$fix->json('id')}/commit")->assertOk();

        $this->assertSame(2, $this->inTenant($this->org, fn () => DB::table('sales_daily_revisions')->count()));
        $after = $this->spa($this->owner, $this->org, 'GET', "/api/venues/{$venueId}/overview")->assertOk();
        $this->assertSame([], $after->json('anomalies'));
        $this->assertSame($rolling28Before['cents'] - 880000 + 220000 + 10000, $after->json('totals.rolling_28.cents'));
        $this->assertNotSame($hashBefore, $this->spa($this->owner, $this->org, 'GET', "/api/venues/{$venueId}/insights/latest")->json('findings_hash'));
        $this->assertSame($decemberBefore, $this->spa($this->owner, $this->org, 'GET', "/api/venues/{$venueId}/metrics?grain=day&from=2025-12-01&to=2025-12-01")->json('data'),
            'days before the correction are untouched');

        // A viewer reads the results but not the uploads.
        $viewer = $this->member($this->org, 'viewer');
        $this->spa($viewer, $this->org, 'GET', "/api/venues/{$venueId}/overview")->assertOk();
        $this->spa($viewer, $this->org, 'GET', "/api/venues/{$venueId}/insights/latest")->assertOk();
        $this->postAs($viewer, "/api/venues/{$venueId}/uploads/inspect", ['file' => $this->file($corrected)])->assertForbidden();
        $this->postAs($viewer, "/api/venues/{$venueId}/uploads", ['file' => $this->file($corrected)] + $mapping)->assertForbidden();
        $this->spa($viewer, $this->org, 'GET', "/api/venues/{$venueId}/uploads")->assertForbidden();
    }
}
