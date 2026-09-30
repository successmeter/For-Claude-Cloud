<?php
// api/app/Http/Controllers/Venues/MetricsController.php
namespace App\Http\Controllers\Venues;

use App\Bench\Rollups;
use App\Hub\Exceptions\HubProblem;
use App\Http\Controllers\Controller;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Revenue by day, week or month with comparisons and coverage (Plan C design §5.2, §3.8).
 * Without `to`, the range ends on the venue's latest day with data (or its current business day
 * when it has none); without `from`, it covers 90 days, 26 weeks or 12 months.
 */
class MetricsController extends Controller
{
    private const DEFAULT_SPAN = ['day' => [89, 'days'], 'week' => [25, 'weeks'], 'month' => [11, 'months']];

    public function __invoke(Request $request, Venue $venue, Rollups $rollups): JsonResponse
    {
        $input = $request->validate([
            'grain' => ['sometimes', 'in:day,week,month'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $grain = $input['grain'] ?? 'day';

        $latest = DB::table('daily_venue_metrics')->where('venue_id', $venue->id)->max('business_date');
        $to = CarbonImmutable::parse($input['to'] ?? $latest ?? CarbonImmutable::now($venue->timezone)->toDateString());
        [$n, $unit] = self::DEFAULT_SPAN[$grain];
        $from = isset($input['from']) ? CarbonImmutable::parse($input['from']) : match ($unit) {
            'days' => $to->subDays($n),
            'weeks' => $to->startOfWeek()->subWeeks($n),
            'months' => $to->startOfMonth()->subMonthsNoOverflow($n),
        };
        if ($to->lt($from)) {
            throw new HubProblem(422, 'validation_failed', 'The range ends before it starts.');
        }
        if ($to->gt($from->addYears(3))) {
            throw new HubProblem(422, 'range_too_long', 'Ask for at most three years at a time.');
        }

        return response()->json([
            'venue_id' => $venue->id,
            'grain' => $grain,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currency' => 'AUD',
            'data' => match ($grain) {
                'day' => $rollups->daily($venue->id, $from, $to),
                'week' => $rollups->weekly($venue->id, $from, $to),
                'month' => $rollups->monthly($venue->id, $from, $to),
            },
        ], options: JSON_PRESERVE_ZERO_FRACTION);
    }
}
