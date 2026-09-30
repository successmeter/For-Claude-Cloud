<?php
// api/tests/Feature/Hub/CompetitorSetRlsTest.php

namespace Tests\Feature\Hub;

use App\Hub\Models\CompetitorSet;
use App\Hub\Models\CompetitorSetMember;
use App\Models\Org;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\TestCase;

class CompetitorSetRlsTest extends TestCase
{
    use RefreshesPrivilegedDatabase;

    private function makeSet(Org $org, array $tools = ['web'], string $name = 'Set'): CompetitorSet
    {
        return TenantContext::run($org->id, fn () => CompetitorSet::create([
            'org_id' => $org->id, 'name' => $name, 'tools' => $tools,
        ]));
    }

    public function test_sets_and_members_are_isolated_by_org(): void
    {
        $a = Org::create(['name' => 'A']);
        $b = Org::create(['name' => 'B']);
        $setA = $this->makeSet($a, name: 'A set');
        $setB = $this->makeSet($b, name: 'B set');
        TenantContext::run($a->id, fn () => CompetitorSetMember::create(['org_id' => $a->id, 'set_id' => $setA->id, 'name' => 'Cafe A']));
        TenantContext::run($b->id, fn () => CompetitorSetMember::create(['org_id' => $b->id, 'set_id' => $setB->id, 'name' => 'Cafe B']));

        TenantContext::run($a->id, function () {
            $this->assertSame(['A set'], DB::table('competitor_sets')->pluck('name')->all());
            $this->assertSame(['Cafe A'], DB::table('competitor_set_members')->pluck('name')->all());
        });
        $this->assertSame(0, DB::table('competitor_sets')->count(), 'no tenant context: nothing visible');
    }

    public function test_tools_round_trip_as_an_array(): void
    {
        $org = Org::create(['name' => 'A']);
        $set = $this->makeSet($org, ['revenue', 'web']);

        $fresh = TenantContext::run($org->id, fn () => CompetitorSet::findOrFail($set->id));
        $this->assertSame(['revenue', 'web'], $fresh->tools);
        $this->assertSame(1, $fresh->version);
    }

    public function test_member_cannot_point_at_another_orgs_set(): void
    {
        $a = Org::create(['name' => 'A']);
        $b = Org::create(['name' => 'B']);
        $setB = $this->makeSet($b);

        // Under A's context, RLS lets A write a member row with org_id A; the composite FK is what
        // stops that row from hanging off B's set.
        $this->expectException(QueryException::class);
        TenantContext::run($a->id, fn () => CompetitorSetMember::create(['org_id' => $a->id, 'set_id' => $setB->id, 'name' => 'X']));
    }

    public function test_tools_must_be_a_non_empty_subset_of_known_tools(): void
    {
        $org = Org::create(['name' => 'A']);

        foreach ([[], ['sms'], ['web', 'sms']] as $tools) {
            try {
                TenantContext::run($org->id, fn () => DB::statement(
                    "INSERT INTO competitor_sets (id, org_id, name, tools) VALUES (gen_random_uuid(), ?, 'S', ?::text[])",
                    [$org->id, '{'.implode(',', $tools).'}'],
                ));
                $this->fail('tools '.json_encode($tools).' was accepted');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_app_user_cannot_delete_sets_or_members(): void
    {
        foreach (['competitor_sets', 'competitor_set_members'] as $table) {
            $this->assertFalse(
                DB::connection('pgsql')->selectOne("SELECT has_table_privilege('app_user', 'public.{$table}', 'DELETE') AS ok")->ok,
                "{$table} must not be deletable by app_user",
            );
        }
    }
}
