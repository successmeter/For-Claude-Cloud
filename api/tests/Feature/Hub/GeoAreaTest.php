<?php
// api/tests/Feature/Hub/GeoAreaTest.php

namespace Tests\Feature\Hub;

use App\Hub\Models\GeoArea;
use App\Models\Org;
use App\Models\Venue;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\GeoAreaFixtureSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\TestCase;

class GeoAreaTest extends TestCase
{
    use RefreshesPrivilegedDatabase;

    public function test_fixture_builds_the_market_to_country_hierarchy(): void
    {
        $this->seed(GeoAreaFixtureSeeder::class);

        $dianella = GeoArea::where('level', 'market')->where('name', 'Dianella')->firstOrFail();
        $chain = [];
        for ($area = $dianella; $area; $area = $area->parent) {
            $chain[] = $area->level.':'.$area->name;
        }

        $this->assertSame(['market:Dianella', 'region:Greater Perth', 'state:Western Australia', 'country:Australia'], $chain);
    }

    public function test_venue_market_must_be_a_known_geo_area(): void
    {
        $this->seed(GeoAreaFixtureSeeder::class);
        $org = Org::create(['name' => 'Org']);
        $market = GeoArea::where('level', 'market')->firstOrFail();

        $venue = TenantContext::run($org->id, fn () => Venue::create([
            'org_id' => $org->id, 'name' => 'V', 'segment' => 'cafe', 'market_id' => $market->id,
        ]));
        $this->assertSame($market->id, $venue->market_id);

        $this->expectException(QueryException::class);
        TenantContext::run($org->id, fn () => Venue::create([
            'org_id' => $org->id, 'name' => 'W', 'segment' => 'cafe', 'market_id' => (string) Str::uuid(),
        ]));
    }

    public function test_app_user_can_read_but_not_write_reference_data(): void
    {
        $this->seed(GeoAreaFixtureSeeder::class);

        $this->assertGreaterThan(0, DB::table('geo_areas')->count());

        $this->expectException(QueryException::class);
        DB::table('geo_areas')->insert(['id' => (string) Str::uuid(), 'level' => 'market', 'code' => 'X', 'name' => 'X']);
    }

    public function test_level_is_constrained(): void
    {
        $this->expectException(QueryException::class);
        DB::connection('pgsql')->table('geo_areas')->insert(['id' => (string) Str::uuid(), 'level' => 'planet', 'code' => 'E', 'name' => 'Earth']);
    }
}
