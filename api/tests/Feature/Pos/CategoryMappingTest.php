<?php
// api/tests/Feature/Pos/CategoryMappingTest.php

namespace Tests\Feature\Pos;

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

/** Plan E Task 7: the owner maps Square categories to food, drinks or other (design E3, §2.2-2.3). */
class CategoryMappingTest extends TestCase
{
    use RefreshesPrivilegedDatabase, SalesFixtures, SpaRequests;

    private Org $org;

    private Venue $venue;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-03-31 09:00', 'Australia/Perth'));
        $this->org = $this->org();
        $this->venue = $this->venue($this->org, ['timezone' => 'Australia/Perth']);
        $this->owner = $this->member($this->org);
        $this->owner->forceFill(['mfa_enabled' => true])->save();

        // Two POS days: categories as Square reported them; revenue is their sum.
        $this->posDay('2026-03-29', ['WINE' => ['Wine', 30000], 'MAINS' => ['Mains', 50000], 'service_charges' => ['Service charges', 2000]]);
        $this->posDay('2026-03-30', ['WINE' => ['Wine', 20000], 'MAINS' => ['Mains', 40000], 'uncategorised' => ['Uncategorised', 1000],
            'CAKES' => ['Cakes', 5000]]);
    }

    private function posDay(string $date, array $categories): void
    {
        $this->inTenant($this->org, function () use ($date, $categories) {
            DB::table('sales_daily')->insert([
                'org_id' => $this->org->id, 'venue_id' => $this->venue->id, 'business_date' => $date,
                'revenue_cents' => array_sum(array_column($categories, 1)), 'gst_inclusive' => true, 'source' => 'pos',
                'created_at' => now(), 'revised_at' => now(),
            ]);
            foreach ($categories as $key => [$name, $cents]) {
                DB::table('sales_daily_categories')->insert([
                    'org_id' => $this->org->id, 'venue_id' => $this->venue->id, 'business_date' => $date, 'source' => 'square',
                    'category_key' => $key, 'category_name' => $name, 'net_cents' => $cents,
                ]);
            }
        });
    }

    private function save(array $mappings, ?User $as = null)
    {
        return $this->spa($as ?? $this->owner, $this->org, 'PUT', "/api/venues/{$this->venue->id}/category-mappings", ['mappings' => $mappings]);
    }

    private function split(): array
    {
        return $this->inTenant($this->org, fn () => DB::table('sales_daily')->orderBy('business_date')->get()
            ->mapWithKeys(fn ($r) => [$r->business_date => [$r->food_cents, $r->drinks_cents, $r->other_cents]])
            ->map(fn ($v) => array_map(fn ($x) => $x === null ? null : (int) $x, $v))->all());
    }

    public function test_categories_are_listed_with_guesses_and_recent_sales(): void
    {
        $this->spa($this->member($this->org, 'manager'), $this->org, 'GET', "/api/venues/{$this->venue->id}/category-mappings")
            ->assertOk()
            ->assertExactJson([
                'venue_id' => $this->venue->id, 'source' => 'square',
                'window' => ['from' => '2026-03-03', 'to' => '2026-03-30'],
                'unmapped' => 5,
                'data' => [
                    ['key' => 'MAINS', 'name' => 'Mains', 'kind' => null, 'guess' => 'food', 'net_cents_28d' => 90000, 'new' => true],
                    ['key' => 'WINE', 'name' => 'Wine', 'kind' => null, 'guess' => 'drinks', 'net_cents_28d' => 50000, 'new' => true],
                    ['key' => 'CAKES', 'name' => 'Cakes', 'kind' => null, 'guess' => 'food', 'net_cents_28d' => 5000, 'new' => true],
                    ['key' => 'service_charges', 'name' => 'Service charges', 'kind' => null, 'guess' => 'other', 'net_cents_28d' => 2000, 'new' => true],
                    ['key' => 'uncategorised', 'name' => 'Uncategorised', 'kind' => null, 'guess' => null, 'net_cents_28d' => 1000, 'new' => true],
                ],
            ]);
        $this->spa($this->member($this->org, 'viewer'), $this->org, 'GET', "/api/venues/{$this->venue->id}/category-mappings")->assertForbidden();
    }

    public function test_saving_derives_the_split_for_every_day_with_history_and_metrics(): void
    {
        $this->save([['key' => 'WINE', 'kind' => 'drinks'], ['key' => 'MAINS', 'kind' => 'food']])
            ->assertOk()->assertJsonPath('unmapped', 3)->assertJsonPath('data.0.kind', 'food');

        // Unmapped categories (cakes, ad hoc items, service charges) count as other until mapped.
        $this->assertSame(['2026-03-29' => [50000, 30000, 2000], '2026-03-30' => [40000, 20000, 6000]], $this->split());
        $this->assertSame(2, $this->inTenant($this->org, fn () => DB::table('sales_daily_revisions')->count()));
        $this->assertNotNull($this->metricsOn($this->venue, '2026-03-29'));
        $this->assertEquals(['venue_id' => $this->venue->id, 'changed' => 2, 'days' => 2], AuditLogEntry::where('action', 'category_mappings.updated')->sole()->meta);

        // A remap recomputes history without calling Square; only changed days get a revision.
        $this->save([['key' => 'CAKES', 'kind' => 'food']])->assertOk();
        $this->assertSame(['2026-03-29' => [50000, 30000, 2000], '2026-03-30' => [45000, 20000, 1000]], $this->split());
        $this->assertSame(3, $this->inTenant($this->org, fn () => DB::table('sales_daily_revisions')->count()));

        // Saving the same mapping again changes nothing.
        $this->save([['key' => 'CAKES', 'kind' => 'food']])->assertOk();
        $this->assertSame(3, $this->inTenant($this->org, fn () => DB::table('sales_daily_revisions')->count()));
        $this->assertSame(2, AuditLogEntry::where('action', 'category_mappings.updated')->count());
    }

    public function test_a_day_with_refunds_outweighing_a_category_gets_no_split(): void
    {
        $this->posDay('2026-03-28', ['WINE' => ['Wine', -5000], 'MAINS' => ['Mains', 20000]]);

        $this->save([['key' => 'WINE', 'kind' => 'drinks'], ['key' => 'MAINS', 'kind' => 'food']])->assertOk();

        $this->assertSame([null, null, null], $this->split()['2026-03-28']);
    }

    public function test_uploaded_days_are_never_touched(): void
    {
        $this->putSales($this->venue, ['2026-03-27' => 10000]);

        $this->save([['key' => 'WINE', 'kind' => 'drinks']])->assertOk();

        $this->assertSame([null, null, null], $this->split()['2026-03-27']);
    }

    public function test_rules(): void
    {
        $this->save([['key' => 'WINE', 'kind' => 'drinks']], $this->member($this->org, 'manager'))->assertForbidden();
        $this->save([['key' => 'NOPE', 'kind' => 'drinks']])->assertUnprocessable()->assertJsonPath('type', 'https://hub/problems/unknown_category');
        $this->save([['key' => 'WINE', 'kind' => 'snacks']])->assertUnprocessable();
        $this->save([])->assertUnprocessable();
        $this->owner->forceFill(['mfa_enabled' => false])->save();
        $this->save([['key' => 'WINE', 'kind' => 'drinks']])->assertForbidden()->assertJsonPath('type', 'https://hub/problems/mfa_required');
        $this->assertSame(0, $this->inTenant($this->org, fn () => DB::table('category_mappings')->count()));
    }

    public function test_another_orgs_venue_is_not_found(): void
    {
        $other = $this->org('Other');
        $stranger = $this->member($other);
        $stranger->forceFill(['mfa_enabled' => true])->save();

        $this->spa($stranger, $other, 'GET', "/api/venues/{$this->venue->id}/category-mappings")->assertNotFound();
        $this->spa($stranger, $other, 'PUT', "/api/venues/{$this->venue->id}/category-mappings", ['mappings' => [['key' => 'WINE', 'kind' => 'food']]])->assertNotFound();
    }
}
