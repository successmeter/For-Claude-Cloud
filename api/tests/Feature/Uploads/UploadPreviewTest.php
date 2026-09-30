<?php
// api/tests/Feature/Uploads/UploadPreviewTest.php

namespace Tests\Feature\Uploads;

use App\Models\AuditLogEntry;
use App\Models\Org;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\SalesFixtures;
use Tests\Support\SpaRequests;
use Tests\TestCase;

class UploadPreviewTest extends TestCase
{
    use RefreshesPrivilegedDatabase, SalesFixtures, SpaRequests;

    private Org $org;

    private Venue $venue;

    private User $manager;

    private const MAPPING = ['date_column' => 'date', 'date_format' => 'YYYY-MM-DD', 'revenue_column' => 'revenue', 'tx_count_column' => 'tx', 'gst_inclusive' => '1'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00', 'Australia/Perth'));
        $this->org = $this->org();
        $this->venue = $this->venue($this->org);
        $this->manager = $this->member($this->org, 'manager');
    }

    private function upload(string $csv, array $mapping = self::MAPPING)
    {
        return $this->actingAs($this->manager)->post("/api/venues/{$this->venue->id}/uploads",
            ['file' => UploadedFile::fake()->createWithContent('sales.csv', $csv)] + $mapping,
            ['X-Hub-Org' => $this->org->id, 'Accept' => 'application/json']);
    }

    private function staged(string $runId): array
    {
        return $this->inTenant($this->org, fn () => DB::table('ingestion_run_rows')->where('run_id', $runId)->orderBy('business_date')
            ->get(['business_date', 'revenue_cents', 'gst_inclusive', 'tx_count', 'change'])->map(fn ($r) => (array) $r)->all());
    }

    public function test_a_new_file_is_previewed_and_staged(): void
    {
        $response = $this->upload("date,revenue,tx,staff\n2026-03-01,\"1,200.50\",40,Alice\n2026-03-02,900,,Bob\n")->assertCreated();

        $response->assertJson([
            'status' => 'previewed',
            'venue_id' => $this->venue->id,
            'mapping' => ['date_column' => 'date', 'date_format' => 'YYYY-MM-DD', 'revenue_column' => 'revenue', 'tx_count_column' => 'tx', 'gst_inclusive' => true],
            'summary' => ['rows' => 2, 'new' => 2, 'changed' => 0, 'unchanged' => 0, 'problems' => 0, 'problems_truncated' => false, 'first_date' => '2026-03-01', 'last_date' => '2026-03-02'],
            'problems' => [],
        ]);
        $this->assertSame([
            ['business_date' => '2026-03-01', 'revenue_cents' => 120050, 'gst_inclusive' => true, 'tx_count' => 40, 'change' => 'new'],
            ['business_date' => '2026-03-02', 'revenue_cents' => 90000, 'gst_inclusive' => true, 'tx_count' => null, 'change' => 'new'],
        ], $this->staged($response->json('id')));

        $run = $this->inTenant($this->org, fn () => DB::table('ingestion_runs')->find($response->json('id')));
        $this->assertSame([hash('sha256', "date,revenue,tx,staff\n2026-03-01,\"1,200.50\",40,Alice\n2026-03-02,900,,Bob\n"), 2, 0],
            [$run->file_sha256, (int) $run->row_count, (int) $run->basis_revision]);
        $this->assertSame('2026-03-21 10:00:00', CarbonImmutable::parse($run->expires_at)->setTimezone('Australia/Perth')->format('Y-m-d H:i:s'));
        $this->assertSame(1, AuditLogEntry::where('action', 'upload.received')->count());
    }

    public function test_known_days_are_unchanged_or_changed(): void
    {
        $this->putSales($this->venue, ['2026-03-01' => 100000, '2026-03-02' => 90000, '2026-03-03' => 80000]);

        $response = $this->upload("date,revenue,tx\n2026-03-01,1000,\n2026-03-02,950,\n2026-03-03,800,5\n2026-03-04,700,\n")->assertCreated();

        $response->assertJson(['summary' => ['new' => 1, 'changed' => 2, 'unchanged' => 1]]);
        $this->assertSame(['unchanged', 'changed', 'changed', 'new'], array_column($this->staged($response->json('id')), 'change'),
            'a new transaction count is a change too');
        $this->assertGreaterThan(0, (int) $this->inTenant($this->org, fn () => DB::table('ingestion_runs')->value('basis_revision')));
    }

    public function test_a_different_gst_basis_is_a_change(): void
    {
        $this->putSales($this->venue, ['2026-03-01' => 100000]);

        $response = $this->upload("date,revenue,tx\n2026-03-01,1000,\n", ['gst_inclusive' => 'false'] + self::MAPPING)->assertCreated();

        $this->assertSame([false, 'changed'], [$this->staged($response->json('id'))[0]['gst_inclusive'], $this->staged($response->json('id'))[0]['change']]);
    }

    public function test_every_problem_is_reported_by_row_and_column(): void
    {
        $csv = implode("\n", [
            'date,revenue,tx',
            'Total,5000,',          // 2 date_unparseable (a totals footer)
            '2026-03-21,10,',       // 3 date_in_future (venue is in Perth; today is 03-20)
            '2014-12-31,10,',       // 4 date_too_old
            '2026-03-01,10,',       // 5 ok
            '2026-03-01,11,',       // 6 date_duplicate
            '2026-03-02,abc,',      // 7 revenue_unparseable
            '2026-03-03,-1,',       // 8 revenue_negative
            '2026-03-04,10000001,', // 9 revenue_too_large
            '2026-03-05,10,1.5',    // 10 tx_count_invalid
            '2026-03-06,,',         // 11 revenue_unparseable (blank)
        ])."\n";

        $response = $this->upload($csv)->assertCreated();

        $this->assertSame([
            ['row' => 2, 'column' => 'date', 'code' => 'date_unparseable'],
            ['row' => 3, 'column' => 'date', 'code' => 'date_in_future'],
            ['row' => 4, 'column' => 'date', 'code' => 'date_too_old'],
            ['row' => 6, 'column' => 'date', 'code' => 'date_duplicate'],
            ['row' => 7, 'column' => 'revenue', 'code' => 'revenue_unparseable'],
            ['row' => 8, 'column' => 'revenue', 'code' => 'revenue_negative'],
            ['row' => 9, 'column' => 'revenue', 'code' => 'revenue_too_large'],
            ['row' => 10, 'column' => 'tx', 'code' => 'tx_count_invalid'],
            ['row' => 11, 'column' => 'revenue', 'code' => 'revenue_unparseable'],
        ], $response->json('problems'));
        $this->assertSame([10, 1, 9], [$response->json('summary.rows'), $response->json('summary.new'), $response->json('summary.problems')]);
        $this->assertStringNotContainsString('abc', $response->getContent(), 'cell content is never echoed');
    }

    public function test_today_in_the_venues_time_zone_is_allowed(): void
    {
        $this->upload("date,revenue,tx\n2026-03-20,10,\n")->assertCreated()->assertJsonPath('summary.problems', 0);
    }

    public function test_problems_stop_at_200(): void
    {
        $rows = implode("\n", array_fill(0, 250, 'bad,1,'));

        $response = $this->upload("date,revenue,tx\n{$rows}\n")->assertCreated();

        $this->assertCount(200, $response->json('problems'));
        $this->assertSame([250, true], [$response->json('summary.problems'), $response->json('summary.problems_truncated')]);
    }

    public function test_an_ex_gst_file_is_stored_as_supplied(): void
    {
        $response = $this->upload("date,revenue,tx\n2026-03-01,1000,\n", ['gst_inclusive' => '0'] + self::MAPPING)->assertCreated();

        $this->assertSame([100000, false], [$this->staged($response->json('id'))[0]['revenue_cents'], $this->staged($response->json('id'))[0]['gst_inclusive']]);
    }

    public function test_a_bad_mapping_is_refused_before_any_work(): void
    {
        $this->upload("date,revenue\n2026-03-01,1\n", ['revenue_column' => 'sales'] + self::MAPPING)
            ->assertStatus(422)->assertJsonPath('type', 'https://hub/problems/mapping_invalid');
        $this->upload("date,revenue\n2026-03-01,1\n", ['date_format' => 'MM/DD/YYYY', 'tx_count_column' => ''] + self::MAPPING)
            ->assertStatus(422)->assertJsonPath('type', 'https://hub/problems/mapping_invalid');

        $this->assertSame(0, $this->inTenant($this->org, fn () => DB::table('ingestion_runs')->count()));
    }

    public function test_viewers_cannot_upload(): void
    {
        $this->actingAs($this->member($this->org, 'viewer'))->post("/api/venues/{$this->venue->id}/uploads",
            ['file' => UploadedFile::fake()->createWithContent('s.csv', "date,revenue\n")] + self::MAPPING,
            ['X-Hub-Org' => $this->org->id, 'Accept' => 'application/json'])->assertForbidden();
    }
}
