<?php
// api/database/seeders/GeoAreaFixtureSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A single market-to-country chain for tests and local development, not the ABS data load.
 *
 * Writes through the privileged `pgsql` connection (app_user may only read geo_areas), so the rows
 * are committed outside a test's rolled-back transaction. Fixed ids plus an upsert keep it
 * idempotent when several tests seed it.
 */
class GeoAreaFixtureSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['id' => '00000000-0000-4000-8000-00000000a001', 'parent_id' => null, 'level' => 'country', 'code' => 'AUS', 'name' => 'Australia'],
            ['id' => '00000000-0000-4000-8000-00000000a002', 'parent_id' => '00000000-0000-4000-8000-00000000a001', 'level' => 'state', 'code' => '5', 'name' => 'Western Australia'],
            ['id' => '00000000-0000-4000-8000-00000000a003', 'parent_id' => '00000000-0000-4000-8000-00000000a002', 'level' => 'region', 'code' => '5GPER', 'name' => 'Greater Perth'],
            ['id' => '00000000-0000-4000-8000-00000000a004', 'parent_id' => '00000000-0000-4000-8000-00000000a003', 'level' => 'market', 'code' => 'SAL50378', 'name' => 'Dianella'],
        ];

        foreach ($rows as $row) {
            DB::connection('pgsql')->table('geo_areas')->upsert($row, ['id'], ['parent_id', 'level', 'code', 'name']);
        }
    }
}
