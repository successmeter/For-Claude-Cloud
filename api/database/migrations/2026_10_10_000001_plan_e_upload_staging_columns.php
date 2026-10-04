<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan E Task 3: staged upload rows carry food/drinks/other and covers. A covers-only file stages
 * rows without revenue (and so without a sales change); such a row always has covers.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ingestion_run_rows
            ALTER COLUMN revenue_cents DROP NOT NULL,
            ALTER COLUMN change DROP NOT NULL,
            ADD COLUMN food_cents bigint NULL CHECK (food_cents >= 0),
            ADD COLUMN drinks_cents bigint NULL CHECK (drinks_cents >= 0),
            ADD COLUMN other_cents bigint NULL CHECK (other_cents >= 0),
            ADD COLUMN covers integer NULL CHECK (covers BETWEEN 0 AND 100000),
            ADD COLUMN covers_changed boolean NOT NULL DEFAULT false');
        DB::statement('ALTER TABLE ingestion_run_rows ADD CONSTRAINT ingestion_run_rows_sales_or_covers CHECK (
            (revenue_cents IS NOT NULL AND change IS NOT NULL)
            OR (revenue_cents IS NULL AND change IS NULL AND covers IS NOT NULL AND food_cents IS NULL))');
    }

    public function down(): void
    {
        DB::statement('DELETE FROM ingestion_run_rows WHERE revenue_cents IS NULL');
        DB::statement('ALTER TABLE ingestion_run_rows
            DROP CONSTRAINT ingestion_run_rows_sales_or_covers,
            DROP COLUMN food_cents, DROP COLUMN drinks_cents, DROP COLUMN other_cents, DROP COLUMN covers, DROP COLUMN covers_changed,
            ALTER COLUMN revenue_cents SET NOT NULL,
            ALTER COLUMN change SET NOT NULL');
    }
};
