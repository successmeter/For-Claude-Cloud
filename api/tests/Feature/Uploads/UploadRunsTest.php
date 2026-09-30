<?php
// api/tests/Feature/Uploads/UploadRunsTest.php

namespace Tests\Feature\Uploads;

use App\Ingest\Csv\CsvReader;
use App\Ingest\Upload\MappingDetector;
use App\Models\AuditLogEntry;
use App\Models\Org;
use App\Models\User;
use App\Models\Venue;
use App\Support\CsvWriter;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\SalesFixtures;
use Tests\Support\SpaRequests;
use Tests\TestCase;

class UploadRunsTest extends TestCase
{
    use RefreshesPrivilegedDatabase, SalesFixtures, SpaRequests;

    private Org $org;

    private Venue $venue;

    private User $manager;

    private const MAPPING = ['date_column' => 'date', 'date_format' => 'YYYY-MM-DD', 'revenue_column' => 'revenue', 'gst_inclusive' => '1'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('snapshots');
        $this->travelTo(CarbonImmutable::parse('2026-03-31 12:00', 'Australia/Perth'));
        $this->org = $this->org();
        $this->venue = $this->venue($this->org);
        $this->manager = $this->member($this->org, 'manager');
    }

    private function preview(string $csv, ?Venue $venue = null): string
    {
        $venue ??= $this->venue;

        return $this->actingAs($this->manager)->post("/api/venues/{$venue->id}/uploads",
            ['file' => UploadedFile::fake()->createWithContent('sales.csv', $csv)] + self::MAPPING,
            ['X-Hub-Org' => $this->org->id, 'Accept' => 'application/json'])->assertCreated()->json('id');
    }

    public function test_listing_is_per_venue_newest_first(): void
    {
        $older = $this->preview("date,revenue\n2026-03-01,1\n");
        $this->travel(1)->minutes();
        $newer = $this->preview("date,revenue\n2026-03-02,1\n");
        $this->preview("date,revenue\n2026-03-02,1\n", $this->venue($this->org, ['name' => 'Other venue']));

        $response = $this->spa($this->manager, $this->org, 'GET', "/api/venues/{$this->venue->id}/uploads")->assertOk();

        $this->assertSame([$newer, $older], array_column($response->json('data'), 'id'));
        $this->assertSame(['page' => 1, 'per_page' => 20, 'total' => 2], $response->json('meta'));
    }

    public function test_listing_pages(): void
    {
        $this->inTenant($this->org, function () {
            for ($i = 0; $i < 21; $i++) {
                DB::table('ingestion_runs')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(), 'org_id' => $this->org->id, 'venue_id' => $this->venue->id,
                    'method' => 'upload', 'status' => 'expired', 'created_at' => now()->subMinutes($i),
                ]);
            }
        });

        $page2 = $this->spa($this->manager, $this->org, 'GET', "/api/venues/{$this->venue->id}/uploads?page=2")->assertOk();

        $this->assertCount(1, $page2->json('data'));
        $this->assertSame(21, $page2->json('meta.total'));
    }

    public function test_show_a_run(): void
    {
        $run = $this->preview("date,revenue\n2026-03-01,1\n");

        $this->spa($this->manager, $this->org, 'GET', "/api/uploads/{$run}")
            ->assertOk()->assertJson(['id' => $run, 'status' => 'previewed', 'summary' => ['new' => 1]]);
    }

    public function test_changed_days_old_against_new_in_pages_of_50(): void
    {
        $this->putSales($this->venue, $this->series('2026-01-01', '2026-03-01', fn () => 1000));
        $rows = implode("\n", array_map(fn ($d) => CarbonImmutable::parse('2026-01-01')->addDays($d)->toDateString().',20', range(0, 59)));
        $run = $this->preview("date,revenue\n{$rows}\n2026-03-02,5\n");

        $first = $this->spa($this->manager, $this->org, 'GET', "/api/uploads/{$run}/changes")->assertOk();
        $this->assertCount(50, $first->json('data'));
        $this->assertSame(['date' => '2026-01-01', 'old' => ['revenue_cents' => 1000, 'gst_inclusive' => true, 'tx_count' => null],
            'new' => ['revenue_cents' => 2000, 'gst_inclusive' => true, 'tx_count' => null]], $first->json('data.0'));
        $this->assertSame(['page' => 1, 'per_page' => 50, 'total' => 60], $first->json('meta'));
        $this->assertCount(10, $this->spa($this->manager, $this->org, 'GET', "/api/uploads/{$run}/changes?page=2")->json('data'));
    }

    public function test_discard(): void
    {
        $run = $this->preview("date,revenue\n2026-03-01,1\n");

        $this->spa($this->manager, $this->org, 'DELETE', "/api/uploads/{$run}")->assertOk()->assertJsonPath('status', 'discarded');

        $this->assertSame(0, $this->inTenant($this->org, fn () => DB::table('ingestion_run_rows')->count()));
        $this->assertSame(1, AuditLogEntry::where('action', 'upload.discarded')->count());
        $this->spa($this->manager, $this->org, 'POST', "/api/uploads/{$run}/commit")->assertStatus(409);
        $this->spa($this->manager, $this->org, 'DELETE', "/api/uploads/{$run}")->assertStatus(409)
            ->assertJsonPath('type', 'https://hub/problems/run_not_discardable');
        $this->spa($this->manager, $this->org, 'GET', "/api/uploads/{$run}/changes")->assertStatus(409);
    }

    public function test_viewers_see_none_of_it(): void
    {
        $run = $this->preview("date,revenue\n2026-03-01,1\n");
        $viewer = $this->member($this->org, 'viewer');

        $this->spa($viewer, $this->org, 'GET', "/api/venues/{$this->venue->id}/uploads")->assertForbidden();
        $this->spa($viewer, $this->org, 'GET', "/api/uploads/{$run}")->assertForbidden();
        $this->spa($viewer, $this->org, 'GET', "/api/uploads/{$run}/changes")->assertForbidden();
        $this->spa($viewer, $this->org, 'DELETE', "/api/uploads/{$run}")->assertForbidden();
    }

    public function test_another_org_cannot_reach_a_run(): void
    {
        $run = $this->preview("date,revenue\n2026-03-01,1\n");
        $other = $this->org('Other');

        $this->spa($this->member($other), $other, 'GET', "/api/uploads/{$run}")->assertNotFound();
    }

    public function test_the_template_is_a_header_the_detector_understands(): void
    {
        $response = $this->actingAs($this->manager)->get('/api/uploads/template.csv')->assertOk();

        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename=sales-template.csv', $response->headers->get('Content-Disposition'));
        $proposal = (new MappingDetector)->propose((new CsvReader(1 << 20, 10))->read($response->getContent()), true);
        $this->assertSame(['date', 'revenue', 'transactions'], [$proposal['date_column'], $proposal['revenue_column'], $proposal['tx_count_column']]);
    }

    public function test_the_template_needs_a_signed_in_user(): void
    {
        $this->getJson('/api/uploads/template.csv')->assertUnauthorized();
    }

    public function test_csv_writer_escapes_formulas(): void
    {
        $this->assertSame("a,b,c,d,e,f,g\n'=1+1,'+1,'-1,'@SUM(A1),\"'\tx\",plain,\"q\"\"uote\"\n",
            CsvWriter::write(['a', 'b', 'c', 'd', 'e', 'f', 'g'], [['=1+1', '+1', '-1', '@SUM(A1)', "\tx", 'plain', 'q"uote']]));
    }
}
