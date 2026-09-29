<?php
// api/tests/Feature/Ingest/HousekeepingTest.php

namespace Tests\Feature\Ingest;

use App\Models\Org;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\SpaRequests;
use Tests\TestCase;

class HousekeepingTest extends TestCase
{
    use RefreshesPrivilegedDatabase, SpaRequests;

    private const MAPPING = ['date_column' => 'date', 'date_format' => 'YYYY-MM-DD', 'revenue_column' => 'revenue', 'gst_inclusive' => '1'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('snapshots');
        $this->travelTo(CarbonImmutable::parse('2026-03-31 12:00', 'Australia/Perth'));
    }

    /** @return array{Org, Venue, \App\Models\User} */
    private function tenant(string $name): array
    {
        $org = $this->org($name);

        return [$org, $this->venue($org), $this->member($org, 'owner')];
    }

    private function preview(array $tenant, string $day = '01'): string
    {
        [$org, $venue, $user] = $tenant;

        return $this->actingAs($user)->post("/api/venues/{$venue->id}/uploads",
            ['file' => UploadedFile::fake()->createWithContent('s.csv', "date,revenue\n2026-03-{$day},10\n")] + self::MAPPING,
            ['X-Hub-Org' => $org->id, 'Accept' => 'application/json'])->assertCreated()->json('id');
    }

    /** Reads in the run's org (each test's transaction is only visible on the app connection). */
    private function runStatus(Org $org, string $id): string
    {
        return $this->inTenant($org, fn () => DB::table('ingestion_runs')->where('id', $id)->value('status'));
    }

    public function test_previews_past_their_expiry_are_expired_in_every_org(): void
    {
        $a = $this->tenant('A');
        $b = $this->tenant('B');
        $oldA = $this->preview($a);
        $oldB = $this->preview($b);
        $this->travel(25)->hours();
        $fresh = $this->preview($a, '02');

        $this->artisan('ingest:expire-runs')->assertSuccessful();

        $this->assertSame(['expired', 'expired', 'previewed'], [$this->runStatus($a[0], $oldA), $this->runStatus($b[0], $oldB), $this->runStatus($a[0], $fresh)]);
        $this->assertSame([$fresh], $this->inTenant($a[0], fn () => DB::table('ingestion_run_rows')->distinct()->pluck('run_id')->all()));
        $this->assertSame([], $this->inTenant($b[0], fn () => DB::table('ingestion_run_rows')->pluck('run_id')->all()));
    }

    public function test_committed_runs_are_not_expired(): void
    {
        $a = $this->tenant('A');
        $run = $this->preview($a);
        $this->spa($a[2], $a[0], 'POST', "/api/uploads/{$run}/commit")->assertOk();
        $this->travel(25)->hours();

        $this->artisan('ingest:expire-runs')->assertSuccessful();

        $this->assertSame('committed', $this->runStatus($a[0], $run));
    }

    public function test_snapshots_are_pruned_after_their_retention(): void
    {
        $a = $this->tenant('A');
        $old = $this->preview($a);
        $this->spa($a[2], $a[0], 'POST', "/api/uploads/{$old}/commit")->assertOk();
        $this->travel(80)->days();
        $recent = $this->preview($a, '02');
        $this->spa($a[2], $a[0], 'POST', "/api/uploads/{$recent}/commit")->assertOk();
        $this->travel(11)->days();

        $this->artisan('ingest:prune-snapshots')->assertSuccessful();

        $this->assertSame([$recent], $this->inTenant($a[0], fn () => DB::table('source_snapshots')->pluck('run_id')->all()));
        $this->assertSame(["{$a[0]->id}/{$recent}.csv.enc"], Storage::disk('snapshots')->allFiles());
    }

    public function test_orphaned_files_are_pruned_once_past_retention(): void
    {
        $a = $this->tenant('A');
        $this->preview($a); // registers the org for housekeeping
        $disk = Storage::disk('snapshots');
        $disk->put("{$a[0]->id}/orphan-old.csv.enc", 'x');
        touch($disk->path("{$a[0]->id}/orphan-old.csv.enc"), now()->subDays(91)->getTimestamp());
        $disk->put("{$a[0]->id}/orphan-new.csv.enc", 'x');

        $this->artisan('ingest:prune-snapshots')->assertSuccessful();

        $this->assertSame(["{$a[0]->id}/orphan-new.csv.enc"], $disk->allFiles());
    }

    public function test_both_commands_are_scheduled(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => [$e->command, $e->expression]);

        $this->assertTrue($events->contains(fn ($e) => str_contains($e[0], 'ingest:expire-runs') && $e[1] === '*/15 * * * *'));
        $this->assertTrue($events->contains(fn ($e) => str_contains($e[0], 'ingest:prune-snapshots') && $e[1] === '0 3 * * *'));
    }
}
