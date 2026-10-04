<?php
// api/tests/Feature/PlanE/SplitAndCoversTablesTest.php

namespace Tests\Feature\PlanE;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\SpaRequests;
use Tests\TestCase;

/** Plan E Task 1: the food/drinks/other split, covers, POS categories and mappings. */
class SplitAndCoversTablesTest extends TestCase
{
    use RefreshesPrivilegedDatabase, SpaRequests;

    private function sale(array $row): void
    {
        DB::table('sales_daily')->insert($row + [
            'business_date' => '2026-09-01', 'revenue_cents' => 1000, 'gst_inclusive' => true, 'source' => 'pos',
            'created_at' => now(), 'revised_at' => now(),
        ]);
    }

    public function test_the_split_is_all_or_nothing_and_adds_up(): void
    {
        $org = $this->org();
        $venue = $this->venue($org);
        $base = ['org_id' => $org->id, 'venue_id' => $venue->id];

        $this->inTenant($org, fn () => $this->sale($base + ['food_cents' => 600, 'drinks_cents' => 300, 'other_cents' => 100]));
        $this->inTenant($org, fn () => $this->sale($base + ['business_date' => '2026-09-02']));

        foreach ([
            ['food_cents' => 600, 'drinks_cents' => 300, 'other_cents' => 99],   // doesn't add up
            ['food_cents' => 600, 'drinks_cents' => 400, 'other_cents' => null], // partial
            ['food_cents' => -1, 'drinks_cents' => 901, 'other_cents' => 100],   // negative
        ] as $i => $split) {
            try {
                $this->inTenant($org, fn () => $this->sale($base + ['business_date' => '2026-09-1'.$i] + $split));
                $this->fail('accepted '.json_encode($split));
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode(), json_encode($split));
            }
        }
    }

    public function test_covers_are_counts_from_a_known_source(): void
    {
        $org = $this->org();
        $venue = $this->venue($org);
        $row = ['org_id' => $org->id, 'venue_id' => $venue->id, 'business_date' => '2026-09-01', 'updated_at' => now()];

        $this->inTenant($org, fn () => DB::table('daily_covers')->insert($row + ['covers' => 120, 'source' => 'manual']));

        foreach ([['covers' => -1, 'source' => 'manual'], ['covers' => 10, 'source' => 'guess']] as $i => $bad) {
            try {
                $this->inTenant($org, fn () => DB::table('daily_covers')->insert(['business_date' => '2026-09-0'.($i + 2)] + $bad + $row));
                $this->fail('accepted '.json_encode($bad));
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode());
            }
        }
    }

    public function test_every_new_table_is_isolated_by_org(): void
    {
        $a = $this->org('A');
        $b = $this->org('B');
        $venue = $this->venue($a);
        $this->inTenant($a, function () use ($a, $venue) {
            $key = ['org_id' => $a->id, 'venue_id' => $venue->id];
            DB::table('daily_covers')->insert($key + ['business_date' => '2026-09-01', 'covers' => 80, 'source' => 'manual', 'updated_at' => now()]);
            DB::table('daily_covers_revisions')->insert($key + ['business_date' => '2026-09-01', 'new_covers' => 80, 'new_source' => 'manual', 'changed_at' => now()]);
            DB::table('sales_daily_categories')->insert($key + ['business_date' => '2026-09-01', 'source' => 'square', 'category_key' => 'CAT1', 'category_name' => 'Wine', 'net_cents' => 5000]);
            DB::table('category_mappings')->insert($key + ['source' => 'square', 'category_key' => 'CAT1', 'kind' => 'drinks', 'mapped_at' => now()]);
        });

        foreach (['daily_covers', 'daily_covers_revisions', 'sales_daily_categories', 'category_mappings'] as $table) {
            $this->assertSame(1, $this->inTenant($a, fn () => DB::table($table)->count()), $table);
            $this->assertSame(0, $this->inTenant($b, fn () => DB::table($table)->count()), $table);
        }

        // Writing into another org's venue is refused by the policy.
        $this->expectException(QueryException::class);
        $this->inTenant($b, fn () => DB::table('daily_covers')->insert([
            'org_id' => $a->id, 'venue_id' => $venue->id, 'business_date' => '2026-09-02', 'covers' => 1, 'source' => 'manual', 'updated_at' => now(),
        ]));
    }

    public function test_covers_history_cannot_be_rewritten(): void
    {
        $org = $this->org();
        $venue = $this->venue($org);
        $this->inTenant($org, fn () => DB::table('daily_covers_revisions')->insert([
            'org_id' => $org->id, 'venue_id' => $venue->id, 'business_date' => '2026-09-01', 'new_covers' => 5, 'new_source' => 'manual', 'changed_at' => now(),
        ]));

        $this->expectException(QueryException::class);
        $this->inTenant($org, fn () => DB::table('daily_covers_revisions')->update(['new_covers' => 6]));
    }
}
