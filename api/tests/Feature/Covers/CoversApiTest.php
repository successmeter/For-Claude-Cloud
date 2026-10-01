<?php
// api/tests/Feature/Covers/CoversApiTest.php

namespace Tests\Feature\Covers;

use App\Covers\CoversService;
use App\Models\AuditLogEntry;
use App\Models\Org;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\SalesFixtures;
use Tests\Support\SpaRequests;
use Tests\TestCase;

/** Plan E Task 2: daily covers entered by the venue (design E2, §2.1a, §2.4, §4). */
class CoversApiTest extends TestCase
{
    use RefreshesPrivilegedDatabase, SalesFixtures, SpaRequests;

    private Org $org;

    private Venue $venue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-03-31 12:00', 'Australia/Perth'));
        $this->org = $this->org();
        $this->venue = $this->venue($this->org, ['timezone' => 'Australia/Perth']);
    }

    private function putCovers(User $as, array $days)
    {
        return $this->spa($as, $this->org, 'PUT', "/api/venues/{$this->venue->id}/covers", ['days' => $days]);
    }

    private function stored(): array
    {
        return $this->inTenant($this->org, fn () => DB::table('daily_covers')->orderBy('business_date')->get()
            ->mapWithKeys(fn ($r) => [$r->business_date => [(int) $r->covers, $r->source]])->all());
    }

    public function test_owners_and_managers_save_covers_and_everyone_reads_them(): void
    {
        $owner = $this->member($this->org);
        $manager = $this->member($this->org, 'manager');
        $viewer = $this->member($this->org, 'viewer');

        $this->putCovers($owner, [['date' => '2026-03-30', 'covers' => 80]])->assertOk();
        $this->putCovers($manager, [['date' => '2026-03-31', 'covers' => 95]])->assertOk();
        $this->putCovers($viewer, [['date' => '2026-03-29', 'covers' => 10]])->assertForbidden();

        $this->spa($viewer, $this->org, 'GET', "/api/venues/{$this->venue->id}/covers?from=2026-03-29&to=2026-03-31")
            ->assertOk()
            ->assertExactJson([
                'venue_id' => $this->venue->id, 'from' => '2026-03-29', 'to' => '2026-03-31',
                'days' => [
                    ['date' => '2026-03-29', 'covers' => null, 'source' => null],
                    ['date' => '2026-03-30', 'covers' => 80, 'source' => 'manual'],
                    ['date' => '2026-03-31', 'covers' => 95, 'source' => 'manual'],
                ],
            ]);

        $audit = AuditLogEntry::where('action', 'covers.updated')->get();
        $this->assertCount(2, $audit);
        $this->assertEquals(['venue_id' => $this->venue->id, 'days' => 1, 'first_date' => '2026-03-30', 'last_date' => '2026-03-30'], $audit[0]->meta);
    }

    public function test_get_defaults_to_the_last_28_days(): void
    {
        $this->spa($this->member($this->org, 'viewer'), $this->org, 'GET', "/api/venues/{$this->venue->id}/covers")
            ->assertOk()->assertJsonPath('from', '2026-03-04')->assertJsonPath('to', '2026-03-31')->assertJsonCount(28, 'days');
    }

    public function test_null_clears_a_day_and_history_is_kept(): void
    {
        $owner = $this->member($this->org);
        $this->putCovers($owner, [['date' => '2026-03-30', 'covers' => 80]])->assertOk();
        $this->putCovers($owner, [['date' => '2026-03-30', 'covers' => 82]])->assertOk();
        $this->putCovers($owner, [['date' => '2026-03-30', 'covers' => null]])->assertOk();
        $this->putCovers($owner, [['date' => '2026-03-29', 'covers' => null]])->assertOk();

        $this->assertSame([], $this->stored());
        $history = $this->inTenant($this->org, fn () => DB::table('daily_covers_revisions')->orderBy('id')
            ->get(['old_covers', 'new_covers', 'changed_by'])->map(fn ($r) => [$r->old_covers, $r->new_covers, $r->changed_by])->all());
        $this->assertSame([[null, 80, $owner->id], [80, 82, $owner->id], [82, null, $owner->id]], $history, 'clearing an empty day records nothing');
    }

    public function test_unchanged_days_record_no_history(): void
    {
        $owner = $this->member($this->org);
        $this->putCovers($owner, [['date' => '2026-03-30', 'covers' => 80]])->assertOk();
        $this->putCovers($owner, [['date' => '2026-03-30', 'covers' => 80]])->assertOk();

        $this->assertSame(1, $this->inTenant($this->org, fn () => DB::table('daily_covers_revisions')->count()));
    }

    public function test_validation(): void
    {
        $owner = $this->member($this->org);
        $url = "/api/venues/{$this->venue->id}/covers";

        $this->putCovers($owner, [])->assertUnprocessable();
        $this->putCovers($owner, [['date' => '2026-03-30', 'covers' => -1]])->assertUnprocessable();
        $this->putCovers($owner, [['date' => '2026-03-30', 'covers' => 100001]])->assertUnprocessable();
        $this->putCovers($owner, [['date' => '2026-03-30', 'covers' => 1.5]])->assertUnprocessable();
        $this->putCovers($owner, [['date' => '30/03/2026', 'covers' => 1]])->assertUnprocessable();
        $this->putCovers($owner, [['date' => '2026-03-30', 'covers' => 1], ['date' => '2026-03-30', 'covers' => 2]])->assertUnprocessable();
        $this->putCovers($owner, [['date' => '2026-04-01', 'covers' => 1]])
            ->assertUnprocessable()->assertJsonPath('type', 'https://hub/problems/date_in_future');
        $this->putCovers($owner, array_map(fn ($d) => ['date' => CarbonImmutable::parse('2026-01-01')->addDays($d)->toDateString(), 'covers' => 1], range(0, 62)))
            ->assertUnprocessable();
        $this->spa($owner, $this->org, 'GET', "{$url}?from=2026-01-01&to=2026-03-31")->assertUnprocessable()->assertJsonPath('type', 'https://hub/problems/range_too_long');
        $this->spa($owner, $this->org, 'GET', "{$url}?from=2026-03-31&to=2026-03-01")->assertUnprocessable();

        $this->assertSame([], $this->stored());
    }

    public function test_covers_are_limited_to_the_org(): void
    {
        $other = $this->org('Other');
        $stranger = $this->member($other);
        $this->member($this->org);

        $this->spa($stranger, $other, 'PUT', "/api/venues/{$this->venue->id}/covers", ['days' => [['date' => '2026-03-30', 'covers' => 5]]])->assertNotFound();
        $this->spa($stranger, $other, 'GET', "/api/venues/{$this->venue->id}/covers")->assertNotFound();
        $this->assertSame([], $this->stored());
    }

    public function test_a_booking_feed_never_overwrites_the_venue_figure(): void
    {
        $owner = $this->member($this->org);
        $this->putCovers($owner, [['date' => '2026-03-30', 'covers' => 80]])->assertOk();

        $covers = app(CoversService::class);
        $changed = $this->inTenant($this->org, fn () => $covers->save($this->venue, ['2026-03-29' => 60, '2026-03-30' => 99], 'booking', null));
        $this->assertSame(['2026-03-29'], $changed);
        $this->assertSame(['2026-03-29' => [60, 'booking'], '2026-03-30' => [80, 'manual']], $this->stored());

        // A booking feed can clear its own figure, but not the venue's; the venue overrides a booking figure.
        $this->inTenant($this->org, fn () => $covers->save($this->venue, ['2026-03-30' => null], 'booking', null));
        $this->putCovers($owner, [['date' => '2026-03-29', 'covers' => 65]])->assertOk();
        $this->assertSame(['2026-03-29' => [65, 'manual'], '2026-03-30' => [80, 'manual']], $this->stored());

        $this->inTenant($this->org, fn () => $covers->save($this->venue, ['2026-03-29' => 70], 'upload', null));
        $this->assertSame([70, 'upload'], $this->stored()['2026-03-29'], 'an upload is the venue\'s own figure too');
        $this->assertSame([], $this->inTenant($this->org, fn () => $covers->save($this->venue, ['2026-03-29' => 1], 'booking', null)));
    }

    public function test_saving_covers_recomputes_metrics(): void
    {
        $this->putSales($this->venue, ['2026-03-30' => 400000]);
        $this->putCovers($this->member($this->org), [['date' => '2026-03-30', 'covers' => 80]])->assertOk();

        $this->assertNotNull($this->metricsOn($this->venue, '2026-03-30'));
    }
}
