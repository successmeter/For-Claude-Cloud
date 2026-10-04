<?php
// api/tests/Feature/Uploads/SplitAndCoversUploadTest.php

namespace Tests\Feature\Uploads;

use App\Covers\CoversService;
use App\Models\Org;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\SalesFixtures;
use Tests\Support\SpaRequests;
use Tests\TestCase;

/** Plan E Task 3: uploads carry covers, food and drinks (design §4). */
class SplitAndCoversUploadTest extends TestCase
{
    use RefreshesPrivilegedDatabase, SalesFixtures, SpaRequests;

    private Org $org;

    private Venue $venue;

    private User $manager;

    private const FULL = ['date_column' => 'date', 'date_format' => 'YYYY-MM-DD', 'revenue_column' => 'total', 'gst_inclusive' => '1',
        'covers_column' => 'covers', 'food_column' => 'food', 'drinks_column' => 'drinks'];

    private const COVERS_ONLY = ['date_column' => 'date', 'date_format' => 'YYYY-MM-DD', 'gst_inclusive' => '1', 'covers_column' => 'covers'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('snapshots');
        $this->travelTo(CarbonImmutable::parse('2026-03-20 10:00', 'Australia/Perth'));
        $this->org = $this->org();
        $this->venue = $this->venue($this->org, ['timezone' => 'Australia/Perth']);
        $this->manager = $this->member($this->org, 'manager');
    }

    private function upload(string $csv, array $mapping = self::FULL)
    {
        return $this->actingAs($this->manager)->post("/api/venues/{$this->venue->id}/uploads",
            ['file' => UploadedFile::fake()->createWithContent('sales.csv', $csv)] + $mapping,
            ['X-Hub-Org' => $this->org->id, 'Accept' => 'application/json']);
    }

    private function commit(string $runId)
    {
        return $this->spa($this->manager, $this->org, 'POST', "/api/uploads/{$runId}/commit");
    }

    private function sales(): array
    {
        return $this->inTenant($this->org, fn () => DB::table('sales_daily')->orderBy('business_date')->get()
            ->mapWithKeys(fn ($r) => [$r->business_date => [(int) $r->revenue_cents, $r->food_cents === null ? null : (int) $r->food_cents,
                $r->drinks_cents === null ? null : (int) $r->drinks_cents, $r->other_cents === null ? null : (int) $r->other_cents]])->all());
    }

    private function covers(): array
    {
        return $this->inTenant($this->org, fn () => DB::table('daily_covers')->orderBy('business_date')->get()
            ->mapWithKeys(fn ($r) => [$r->business_date => [(int) $r->covers, $r->source]])->all());
    }

    public function test_a_full_file_writes_the_split_and_covers(): void
    {
        $run = $this->upload("date,total,food,drinks,covers\n2026-03-01,1000,600,350,40\n2026-03-02,800,,,\n2026-03-03,500,500,,25\n")
            ->assertCreated()
            ->assertJsonPath('mapping.covers_column', 'covers')->assertJsonPath('mapping.food_column', 'food')->assertJsonPath('mapping.drinks_column', 'drinks')
            ->assertJsonPath('summary.new', 3)->assertJsonPath('summary.covers', 2)->assertJsonPath('summary.problems', 0)
            ->json('id');

        $this->commit($run)->assertOk();

        $this->assertSame([
            '2026-03-01' => [100000, 60000, 35000, 5000],
            '2026-03-02' => [80000, null, null, null],
            '2026-03-03' => [50000, 50000, 0, 0],
        ], $this->sales());
        $this->assertSame(['2026-03-01' => [40, 'upload'], '2026-03-03' => [25, 'upload']], $this->covers());

        $snapshot = $this->inTenant($this->org, fn () => DB::table('source_snapshots')->sole());
        $plain = app(\App\Services\Encryption\EnvelopeEncryptor::class)->decrypt($this->org->id, Storage::disk('snapshots')->get($snapshot->path));
        $this->assertStringStartsWith("business_date,revenue_cents,gst_inclusive,tx_count,food_cents,drinks_cents,covers\n2026-03-01,100000,true,,60000,35000,40\n", $plain);
    }

    public function test_the_same_file_again_changes_nothing_and_a_new_split_is_a_change_with_history(): void
    {
        $csv = "date,total,food,drinks,covers\n2026-03-01,1000,600,350,40\n";
        $this->commit($this->upload($csv)->json('id'))->assertOk();

        $this->upload($csv)->assertJsonPath('summary.unchanged', 1)->assertJsonPath('summary.covers', 0);

        $run = $this->upload("date,total,food,drinks,covers\n2026-03-01,1000,700,300,41\n")
            ->assertJsonPath('summary.changed', 1)->assertJsonPath('summary.covers', 1)->json('id');
        $this->spa($this->manager, $this->org, 'GET', "/api/uploads/{$run}/changes")->assertOk()
            ->assertJsonPath('data.0.old.food_cents', 60000)->assertJsonPath('data.0.new.food_cents', 70000)
            ->assertJsonPath('data.0.old.drinks_cents', 35000)->assertJsonPath('data.0.new.drinks_cents', 30000);
        $this->commit($run)->assertOk();

        $this->assertSame(['2026-03-01' => [100000, 70000, 30000, 0]], $this->sales());
        $revision = $this->inTenant($this->org, fn () => DB::table('sales_daily_revisions')->sole());
        $this->assertSame([60000, 35000, 5000, 70000, 30000, 0], array_map('intval', [$revision->old_food_cents, $revision->old_drinks_cents,
            $revision->old_other_cents, $revision->new_food_cents, $revision->new_drinks_cents, $revision->new_other_cents]));
        $this->assertSame(['2026-03-01' => [41, 'upload']], $this->covers());
    }

    public function test_row_problems(): void
    {
        $response = $this->upload("date,total,food,drinks,covers\n"
            ."2026-03-01,1000,600,350,abc\n"
            ."2026-03-02,1000,600,350,-1\n"
            ."2026-03-03,1000,600,350,1.5\n"
            ."2026-03-04,1000,700,350,10\n"
            ."2026-03-05,1000,x,350,10\n"
            ."2026-03-06,1000,600,-5,10\n"
            ."2026-03-07,1000,600,350,100001\n")->assertCreated();

        $this->assertSame([
            ['row' => 2, 'column' => 'covers', 'code' => 'covers_invalid'],
            ['row' => 3, 'column' => 'covers', 'code' => 'covers_invalid'],
            ['row' => 4, 'column' => 'covers', 'code' => 'covers_invalid'],
            ['row' => 5, 'column' => 'food', 'code' => 'split_exceeds_total'],
            ['row' => 6, 'column' => 'food', 'code' => 'food_invalid'],
            ['row' => 7, 'column' => 'drinks', 'code' => 'drinks_invalid'],
            ['row' => 8, 'column' => 'covers', 'code' => 'covers_invalid'],
        ], $response->json('problems'));
    }

    public function test_mapping_rules(): void
    {
        $csv = "date,total,food,drinks,covers\n2026-03-01,1000,600,350,40\n";
        $without = fn (string ...$keys) => array_diff_key(self::FULL, array_flip($keys));

        $this->upload($csv, $without('drinks_column'))->assertUnprocessable()->assertJsonPath('type', 'https://hub/problems/mapping_invalid');
        $this->upload($csv, $without('revenue_column'))->assertUnprocessable()->assertJsonPath('type', 'https://hub/problems/mapping_invalid');
        $this->upload($csv, $without('revenue_column', 'food_column', 'drinks_column', 'covers_column'))->assertUnprocessable();
        $this->upload($csv, ['covers_column' => 'total'] + self::FULL)->assertUnprocessable();
        $this->upload($csv, ['covers_column' => 'nope'] + self::FULL)->assertUnprocessable();
    }

    public function test_a_covers_only_file_leaves_sales_alone_and_wins_over_a_booking_feed(): void
    {
        $this->putSales($this->venue, ['2026-03-01' => 120000]);
        $this->inTenant($this->org, fn () => app(CoversService::class)->save($this->venue, ['2026-03-02' => 70], 'booking', null));

        $run = $this->upload("date,covers\n2026-03-01,55\n2026-03-02,66\n2026-03-03,\n", self::COVERS_ONLY)->assertCreated()
            ->assertJsonPath('mapping.revenue_column', null)
            ->assertJsonPath('summary.new', 0)->assertJsonPath('summary.changed', 0)->assertJsonPath('summary.covers', 2)
            ->assertJsonPath('summary.first_date', '2026-03-01')->assertJsonPath('summary.last_date', '2026-03-02')
            ->json('id');
        $this->commit($run)->assertOk();

        $this->assertSame(['2026-03-01' => [120000, null, null, null]], $this->sales());
        $this->assertSame(['2026-03-01' => [55, 'upload'], '2026-03-02' => [66, 'upload']], $this->covers());
        $this->assertNotNull($this->metricsOn($this->venue, '2026-03-01'));
    }
}
