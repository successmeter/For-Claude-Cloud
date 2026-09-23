<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->constrained('orgs')->cascadeOnDelete();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('timezone')->default('Australia/Perth');
            $table->string('segment'); // restaurant | cafe | bar | ...
            $table->string('cuisine')->nullable();
            $table->uuid('market_id')->nullable(); // FK added to geo_areas in Plan B
            $table->time('business_day_cutoff')->default('04:00:00');
            $table->boolean('gst_inclusive_default')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('venues');
    }
};
