<?php
// api/tests/Feature/Uploads/InspectUploadTest.php

namespace Tests\Feature\Uploads;

use App\Ingest\Scanning\ScanResult;
use App\Ingest\Scanning\UploadScanner;
use App\Models\AuditLogEntry;
use App\Models\Org;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\SpaRequests;
use Tests\TestCase;

class InspectUploadTest extends TestCase
{
    use RefreshesPrivilegedDatabase, SpaRequests;

    private Org $org;

    private Venue $venue;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->org();
        $this->venue = $this->venue($this->org);
        $this->manager = $this->member($this->org, 'manager');
    }

    private function inspect(User $user, UploadedFile $file)
    {
        return $this->actingAs($user)->post("/api/venues/{$this->venue->id}/uploads/inspect", ['file' => $file],
            ['X-Hub-Org' => $this->org->id, 'Accept' => 'application/json']);
    }

    private function csv(string $content, string $name = 'sales.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_it_proposes_a_mapping_and_stores_nothing(): void
    {
        $response = $this->inspect($this->manager, $this->csv("Date,Net Sales,Staff\n01/03/2026,\"1,200.00\",Alice\n13/03/2026,900,Bob\n"));

        $response->assertOk()->assertJson([
            'columns' => ['Date', 'Net Sales', 'Staff'],
            'row_count' => 2,
            'sample' => [['01/03/2026', '1,200.00', 'Alice'], ['13/03/2026', '900', 'Bob']],
            'proposal' => ['date_column' => 'Date', 'date_format' => 'DD/MM/YYYY', 'revenue_column' => 'Net Sales', 'gst_inclusive' => true],
        ]);
        foreach (['ingestion_runs', 'ingestion_run_rows', 'source_snapshots', 'sales_daily'] as $table) {
            $this->assertSame(0, DB::connection('pgsql')->table($table)->count(), $table);
        }
    }

    public function test_the_sample_is_the_first_five_rows(): void
    {
        $rows = implode("\n", array_map(fn ($d) => sprintf('2026-03-%02d,%d', $d, $d), range(1, 9)));

        $response = $this->inspect($this->manager, $this->csv("date,revenue\n{$rows}\n"))->assertOk();

        $this->assertCount(5, $response->json('sample'));
        $this->assertSame(9, $response->json('row_count'));
    }

    public function test_viewers_cannot_upload(): void
    {
        $this->inspect($this->member($this->org, 'viewer'), $this->csv("date,revenue\n2026-01-01,1\n"))
            ->assertForbidden()->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_only_csv_files(): void
    {
        $this->inspect($this->manager, $this->csv('PK...', 'sales.xlsx'))
            ->assertStatus(415)->assertJsonPath('type', 'https://hub/problems/unsupported_media_type');
    }

    public function test_file_problems_are_reported(): void
    {
        config(['ingest.max_bytes' => 50]);
        $this->inspect($this->manager, $this->csv(str_repeat('a', 51)))
            ->assertStatus(413)->assertJsonPath('type', 'https://hub/problems/file_too_large');

        config(['ingest.max_bytes' => 1 << 20]);
        $this->inspect($this->manager, $this->csv("caf\xE9,revenue\n"))
            ->assertStatus(422)->assertJsonPath('type', 'https://hub/problems/encoding_not_utf8');
        $this->inspect($this->manager, $this->csv("date,revenue\n2026-01-01,1,2\n"))
            ->assertStatus(422)->assertJson(['type' => 'https://hub/problems/row_too_long', 'row' => 2]);
    }

    public function test_a_missing_file_is_a_validation_problem(): void
    {
        $this->actingAs($this->manager)->post("/api/venues/{$this->venue->id}/uploads/inspect", [], ['X-Hub-Org' => $this->org->id, 'Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('type', 'https://hub/problems/validation_failed');
    }

    public function test_an_infected_file_is_refused_and_audited_without_its_content(): void
    {
        $this->app->instance(UploadScanner::class, new class implements UploadScanner
        {
            public function scan(string $bytes): ScanResult
            {
                return ScanResult::infected('Eicar-Test-Signature');
            }
        });

        $this->inspect($this->manager, $this->csv("date,revenue\n2026-01-01,1\n"))
            ->assertStatus(422)->assertJsonPath('type', 'https://hub/problems/upload_rejected');

        $entry = AuditLogEntry::where('action', 'upload.rejected_malware')->sole();
        $this->assertSame(['signature' => 'Eicar-Test-Signature', 'file_sha256' => hash('sha256', "date,revenue\n2026-01-01,1\n")], $entry->meta);
        $this->assertSame($this->venue->id, $entry->entity_id);
    }

    public function test_a_scanner_failure_refuses_the_file(): void
    {
        $this->app->instance(UploadScanner::class, new class implements UploadScanner
        {
            public function scan(string $bytes): ScanResult
            {
                return ScanResult::error();
            }
        });

        $this->inspect($this->manager, $this->csv("date,revenue\n2026-01-01,1\n"))
            ->assertStatus(503)->assertJsonPath('type', 'https://hub/problems/scanner_unavailable');
    }

    public function test_another_orgs_venue_is_not_found(): void
    {
        $other = $this->venue($this->org('Other'));

        $this->actingAs($this->manager)->post("/api/venues/{$other->id}/uploads/inspect", ['file' => $this->csv("date,revenue\n")],
            ['X-Hub-Org' => $this->org->id, 'Accept' => 'application/json'])->assertNotFound();
    }

    public function test_twenty_uploads_an_hour(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->inspect($this->manager, $this->csv("date,revenue\n2026-01-01,1\n"))->assertOk();
        }

        $this->inspect($this->manager, $this->csv("date,revenue\n2026-01-01,1\n"))
            ->assertStatus(429)->assertHeader('Content-Type', 'application/problem+json');
    }
}
