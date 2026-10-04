<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Covers can come from the POS too (guest counts staff enter on table orders), ranked between the
 * venue's own figure and a booking feed (Plan E design §2.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE daily_covers DROP CONSTRAINT daily_covers_source_check');
        DB::statement("ALTER TABLE daily_covers ADD CONSTRAINT daily_covers_source_check CHECK (source IN ('manual', 'upload', 'pos', 'booking'))");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM daily_covers WHERE source = 'pos'");
        DB::statement('ALTER TABLE daily_covers DROP CONSTRAINT daily_covers_source_check');
        DB::statement("ALTER TABLE daily_covers ADD CONSTRAINT daily_covers_source_check CHECK (source IN ('manual', 'upload', 'booking'))");
    }
};
