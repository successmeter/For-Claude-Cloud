<?php
// api/app/Http/Controllers/Venues/OverviewController.php
namespace App\Http\Controllers\Venues;

use App\Bench\Freshness;
use App\Bench\Rollups;
use App\Http\Controllers\Controller;
use App\Models\Venue;
use App\Strategy\Insights\InsightEngine;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The venue's own performance at a glance (Plan C design §5.3; 04 §4.3 Overview). Everyone in the
 * org may read it. Money is GST-inclusive cents; missing days are null, never zero.
 */
class OverviewController extends Controller
{
    public function __invoke(Venue $venue, Rollups $rollups, InsightEngine $insights): JsonResponse
    {
        $m = DB::table('daily_venue_metrics')->where('venue_id', $venue->id)->orderByDesc('business_date')->first();
        $empty = ['venue_id' => $venue->id, 'currency' => 'AUD', 'latest' => null, 'totals' => null, 'per_cover' => null, 'mix' => null, 'series' => [], 'anomalies' => []];
        if ($m === null) {
            return response()->json($empty + ['freshness' => Freshness::for($venue, null, now())]);
        }

        $latest = CarbonImmutable::parse($m->business_date);
        $window = fn (int $days, $cents, $previous, $lastYear) => [
            'from' => $latest->subDays($days - 1)->toDateString(),
            'to' => $latest->toDateString(),
            'cents' => self::int($cents),
            'previous_cents' => self::int($previous),
            'last_year_cents' => self::int($lastYear),
            'vs_previous_pct' => Rollups::pct($cents, $previous),
            'yoy_pct' => Rollups::pct($cents, $lastYear),
        ];

        return response()->json([
            'venue_id' => $venue->id,
            'currency' => 'AUD',
            'latest' => [
                'date' => $latest->toDateString(),
                'revenue_cents' => (int) $m->revenue_cents,
                'last_week_cents' => self::int($m->same_weekday_last_week_cents),
                'last_year_cents' => self::int($m->same_weekday_last_year_cents),
                'wow_pct' => Rollups::pct($m->revenue_cents, $m->same_weekday_last_week_cents),
                'yoy_pct' => Rollups::pct($m->revenue_cents, $m->same_weekday_last_year_cents),
            ],
            'totals' => [
                'rolling_7' => $window(7, $m->rolling_7_cents, $m->rolling_7_prev_cents, $m->rolling_7_ly_cents),
                'rolling_28' => $window(28, $m->rolling_28_cents, $m->rolling_28_prev_cents, $m->rolling_28_ly_cents),
            ],
            ...$rollups->perCover($venue->id, $latest),
            'series' => $rollups->daily($venue->id, $latest->subDays(89), $latest),
            'anomalies' => array_values(array_filter($insights->latest($venue->id)['findings'], fn ($f) => $f['type'] === 'anomaly_day')),
            'freshness' => Freshness::for($venue, $latest->toDateString(), now()),
        ], options: JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function int(mixed $v): ?int
    {
        return $v === null ? null : (int) $v;
    }
}
