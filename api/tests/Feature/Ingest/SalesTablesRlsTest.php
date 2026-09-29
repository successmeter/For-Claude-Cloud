<?php
// api/tests/Feature/Ingest/SalesTablesRlsTest.php

namespace Tests\Feature\Ingest;

use App\Models\Org;
use App\Models\Venue;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\SpaRequests;
use Tests\TestCase;

class SalesTablesRlsTest extends TestCase
{
    use RefreshesPrivilegedDatabase, SpaRequests;

    private Org $a;

    private Org $b;

    private Venue $venueA;

    private string $runA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->org('A');
        $this->b = $this->org('B');
        $this->venueA = $this->venue($this->a);
        $this->runA = (string) Str::uuid();

        $this->inTenant($this->a, function () {
            $org = $this->a->id;
            $venue = $this->venueA->id;
            DB::table('ingestion_runs')->insert([
                'id' => $this->runA, 'org_id' => $org, 'venue_id' => $venue, 'method' => 'upload',
                'status' => 'previewed', 'basis_revision' => 0, 'created_at' => now(), 'expires_at' => now()->addDay(),
            ]);
            DB::table('ingestion_run_rows')->insert([
                'run_id' => $this->runA, 'org_id' => $org, 'business_date' => '2026-09-01',
                'revenue_cents' => 1000, 'gst_inclusive' => false, 'change' => 'new',
            ]);
            DB::table('source_snapshots')->insert([
                'id' => (string) Str::uuid(), 'org_id' => $org, 'run_id' => $this->runA, 'path' => 'x', 'sha256' => str_repeat('0', 64),
                'bytes' => 1, 'created_at' => now(), 'expires_at' => now()->addDays(90),
            ]);
            DB::table('sales_daily')->insert([
                'org_id' => $org, 'venue_id' => $venue, 'business_date' => '2026-09-01', 'revenue_cents' => 1000,
                'gst_inclusive' => false, 'source' => 'upload', 'ingestion_run_id' => $this->runA,
                'created_at' => now(), 'revised_at' => now(),
            ]);
            DB::table('sales_daily_revisions')->insert([
                'org_id' => $org, 'venue_id' => $venue, 'business_date' => '2026-09-01',
                'old_revenue_cents' => 900, 'old_gst_inclusive' => false, 'new_revenue_cents' => 1000, 'new_gst_inclusive' => false,
                'ingestion_run_id' => $this->runA, 'revised_at' => now(),
            ]);
            DB::table('daily_venue_metrics')->insert([
                'org_id' => $org, 'venue_id' => $venue, 'business_date' => '2026-09-01', 'revenue_cents' => 1100, 'updated_at' => now(),
            ]);
            DB::table('insights')->insert([
                'id' => (string) Str::uuid(), 'org_id' => $org, 'venue_id' => $venue, 'as_of' => '2026-09-01',
                'findings' => '[]', 'findings_hash' => str_repeat('0', 64), 'rules_version' => 1, 'created_at' => now(),
            ]);
        });
    }

    private const TABLES = [
        'ingestion_runs', 'ingestion_run_rows', 'source_snapshots', 'sales_daily',
        'sales_daily_revisions', 'daily_venue_metrics', 'insights',
    ];

    public function test_another_org_sees_nothing(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertSame(1, $this->inTenant($this->a, fn () => DB::table($table)->count()), "{$table} visible to its org");
            $this->assertSame(0, $this->inTenant($this->b, fn () => DB::table($table)->count()), "{$table} leaks across orgs");
        }
    }

    public function test_another_org_cannot_write_rows_for_this_org(): void
    {
        $this->expectException(QueryException::class);
        $this->inTenant($this->b, fn () => DB::table('sales_daily')->insert([
            'org_id' => $this->a->id, 'venue_id' => $this->venueA->id, 'business_date' => '2026-09-02', 'revenue_cents' => 1,
            'gst_inclusive' => true, 'source' => 'upload', 'created_at' => now(), 'revised_at' => now(),
        ]));
    }

    public function test_a_row_cannot_point_at_another_orgs_venue(): void
    {
        $this->expectException(QueryException::class);
        $this->inTenant($this->b, fn () => DB::table('sales_daily')->insert([
            'org_id' => $this->b->id, 'venue_id' => $this->venueA->id, 'business_date' => '2026-09-02', 'revenue_cents' => 1,
            'gst_inclusive' => true, 'source' => 'upload', 'created_at' => now(), 'revised_at' => now(),
        ]));
    }

    public function test_gst_inclusive_revenue_is_generated(): void
    {
        $inc = fn (int $cents, bool $inclusive) => $this->inTenant($this->a, function () use ($cents, $inclusive) {
            DB::table('sales_daily')->where('business_date', '2026-09-01')->update(['revenue_cents' => $cents, 'gst_inclusive' => $inclusive]);

            return (int) DB::table('sales_daily')->value('revenue_inc_gst_cents');
        });

        $this->assertSame(1100, $inc(1000, false));
        $this->assertSame(1099, $inc(999, false)); // 1098.9 rounds half away from zero
        $this->assertSame(999, $inc(999, true));
    }

    public function test_one_row_per_venue_and_day(): void
    {
        $this->expectException(QueryException::class);
        $this->inTenant($this->a, fn () => DB::table('sales_daily')->insert([
            'org_id' => $this->a->id, 'venue_id' => $this->venueA->id, 'business_date' => '2026-09-01', 'revenue_cents' => 5,
            'gst_inclusive' => true, 'source' => 'upload', 'created_at' => now(), 'revised_at' => now(),
        ]));
    }

    public function test_every_write_gets_a_new_revision(): void
    {
        $revisions = $this->inTenant($this->a, function () {
            $first = DB::table('sales_daily')->value('revision');
            DB::table('sales_daily')->update(['revenue_cents' => 2000, 'revision' => DB::raw("nextval('sales_daily_revision_seq')")]);

            return [$first, DB::table('sales_daily')->value('revision')];
        });

        $this->assertGreaterThan($revisions[0], $revisions[1]);
    }

    public function test_negative_amounts_are_refused(): void
    {
        $this->expectException(QueryException::class);
        $this->inTenant($this->a, fn () => DB::table('sales_daily')->update(['revenue_cents' => -1]));
    }

    public function test_revision_history_is_append_only(): void
    {
        foreach (['update' => fn () => DB::table('sales_daily_revisions')->update(['new_revenue_cents' => 1]),
            'delete' => fn () => DB::table('sales_daily_revisions')->delete()] as $what => $write) {
            try {
                $this->inTenant($this->a, $write);
                $this->fail("{$what} on sales_daily_revisions was allowed");
            } catch (QueryException $e) {
                $this->assertStringContainsString('permission denied', $e->getMessage());
            }
        }
    }

    public function test_staged_rows_and_snapshots_can_be_deleted_by_their_org_only(): void
    {
        $this->assertSame(0, $this->inTenant($this->b, fn () => DB::table('ingestion_run_rows')->delete()));
        $this->assertSame(0, $this->inTenant($this->b, fn () => DB::table('source_snapshots')->delete()));
        $this->assertSame(1, $this->inTenant($this->a, fn () => DB::table('ingestion_run_rows')->delete()));
        $this->assertSame(1, $this->inTenant($this->a, fn () => DB::table('source_snapshots')->delete()));
    }

    public function test_deleting_a_venue_removes_its_sales_data(): void
    {
        $this->inTenant($this->a, fn () => $this->venueA->forceDelete());

        foreach (self::TABLES as $table) {
            $this->assertSame(0, DB::connection('pgsql')->table($table)->count(), "{$table} kept rows of a deleted venue");
        }
    }
}
