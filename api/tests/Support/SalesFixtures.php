<?php
// api/tests/Support/SalesFixtures.php

namespace Tests\Support;

use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Writes sales_daily rows directly (inside tenant context), for metric and insight tests. */
trait SalesFixtures
{
    /** @param array<string, int> $days business date => cents */
    protected function putSales(Venue $venue, array $days, bool $gstInclusive = true): void
    {
        $this->inTenantOf($venue, function () use ($venue, $days, $gstInclusive) {
            foreach (array_chunk($days, 500, true) as $chunk) {
                DB::table('sales_daily')->upsert(array_map(fn ($date, $cents) => [
                    'org_id' => $venue->org_id, 'venue_id' => $venue->id, 'business_date' => $date,
                    'revenue_cents' => $cents, 'gst_inclusive' => $gstInclusive, 'source' => 'upload',
                    'created_at' => now(), 'revised_at' => now(),
                ], array_keys($chunk), $chunk), ['venue_id', 'business_date'], ['revenue_cents', 'gst_inclusive', 'revised_at']);
            }
        });
    }

    /** @return array<string, int> every day from $from to $to (inclusive) => $valueFor(date) */
    protected function series(string $from, string $to, \Closure $valueFor): array
    {
        $days = [];
        for ($d = CarbonImmutable::parse($from); $d->lte(CarbonImmutable::parse($to)); $d = $d->addDay()) {
            $days[$d->toDateString()] = $valueFor($d);
        }

        return $days;
    }

    protected function inTenantOf(Venue $venue, \Closure $fn): mixed
    {
        return \App\Services\Tenancy\TenantContext::run($venue->org_id, $fn);
    }

    protected function metricsOn(Venue $venue, string $date): ?object
    {
        return $this->inTenantOf($venue, fn () => DB::table('daily_venue_metrics')
            ->where('venue_id', $venue->id)->where('business_date', $date)->first());
    }
}
