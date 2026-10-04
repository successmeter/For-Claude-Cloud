<?php
// api/app/Http/Controllers/Venues/CoversController.php
namespace App\Http\Controllers\Venues;

use App\Covers\CoversService;
use App\Hub\Exceptions\HubProblem;
use App\Http\Controllers\Concerns\ChecksOrgRole;
use App\Http\Controllers\Controller;
use App\Models\Venue;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Daily covers entered by the venue (Plan E design §4): everyone in the org reads them; owners and
 * managers save up to 62 days at a time, up to today in the venue's time zone.
 */
class CoversController extends Controller
{
    use ChecksOrgRole;

    private const MAX_DAYS = 62;

    public function __construct(private CoversService $covers, private AuditLogger $audit) {}

    public function index(Request $request, Venue $venue): JsonResponse
    {
        $input = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $to = CarbonImmutable::parse($input['to'] ?? CarbonImmutable::now($venue->timezone)->toDateString());
        $from = isset($input['from']) ? CarbonImmutable::parse($input['from']) : $to->subDays(27);
        if ($to->lt($from)) {
            throw new HubProblem(422, 'validation_failed', 'The range ends before it starts.');
        }
        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            throw new HubProblem(422, 'range_too_long', 'Ask for at most '.self::MAX_DAYS.' days at a time.');
        }

        return $this->present($venue, $from, $to);
    }

    public function update(Request $request, Venue $venue): JsonResponse
    {
        $this->requireWriter($request);
        $input = $request->validate([
            'days' => ['required', 'array', 'min:1', 'max:'.self::MAX_DAYS],
            'days.*.date' => ['required', 'date_format:Y-m-d', 'distinct'],
            'days.*.covers' => ['present', 'nullable', 'integer', 'between:0,100000'],
        ]);

        $today = CarbonImmutable::now($venue->timezone)->toDateString();
        $days = [];
        foreach ($input['days'] as $day) {
            if ($day['date'] > $today) {
                throw new HubProblem(422, 'date_in_future', 'Covers can be entered up to today.');
            }
            $days[$day['date']] = $day['covers'] === null ? null : (int) $day['covers'];
        }
        ksort($days);

        $changed = $this->covers->save($venue, $days, 'manual', $request->user()->id);
        if ($changed !== []) {
            $this->audit->record('covers.updated', 'venue', $venue->id, $venue->org_id, [
                'venue_id' => $venue->id, 'days' => count($changed), 'first_date' => $changed[0], 'last_date' => end($changed),
            ]);
        }

        return $this->present($venue, CarbonImmutable::parse(array_key_first($days)), CarbonImmutable::parse(array_key_last($days)));
    }

    private function present(Venue $venue, CarbonImmutable $from, CarbonImmutable $to): JsonResponse
    {
        return response()->json([
            'venue_id' => $venue->id,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $this->covers->days($venue, $from, $to),
        ]);
    }
}
