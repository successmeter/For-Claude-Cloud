<?php
// api/app/Http/Controllers/Venues/VenueController.php
namespace App\Http\Controllers\Venues;

use App\Http\Controllers\Concerns\ChecksOrgRole;
use App\Http\Controllers\Controller;
use App\Models\Venue;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * First-party venue API (Plan C Task 1). Everyone in the org reads; owners and managers write.
 * `market_id` is not writable here: onboarding resolves it from the address (Plan D).
 */
class VenueController extends Controller
{
    use ChecksOrgRole;

    public const SEGMENTS = ['restaurant', 'cafe', 'bar', 'other'];

    public function __construct(private AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => Venue::orderBy('name')->get()->map(fn (Venue $v) => self::present($v))]);
    }

    public function show(Venue $venue): JsonResponse
    {
        return response()->json(self::present($venue));
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireWriter($request);
        $data = $request->validate($this->rules(creating: true));

        $venue = Venue::create($this->normalise($data) + ['org_id' => $this->orgId($request)]);
        $this->audit->record('venue.created', 'venue', $venue->id, $venue->org_id);

        return response()->json(self::present($venue->refresh()), 201);
    }

    public function update(Request $request, Venue $venue): JsonResponse
    {
        $this->requireWriter($request);
        $data = $request->validate($this->rules(creating: false));

        $venue->fill($this->normalise($data));
        $changed = array_keys($venue->getDirty());
        sort($changed);
        $venue->save();
        if ($changed !== []) {
            $this->audit->record('venue.updated', 'venue', $venue->id, $venue->org_id, ['fields' => $changed]);
        }

        return response()->json(self::present($venue->refresh()));
    }

    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'min:1', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'timezone' => ['sometimes', 'timezone:all'],
            'segment' => [$required, Rule::in(self::SEGMENTS)],
            'cuisine' => ['sometimes', 'nullable', 'string', 'max:100'],
            // A trading day ends between midnight and 06:00 (design §3.1; 02 §2.1).
            'business_day_cutoff' => ['sometimes', 'regex:/^0[0-5]:[0-5]\d$|^06:00$/'],
            'gst_inclusive_default' => ['sometimes', 'boolean'],
        ];
    }

    private function normalise(array $data): array
    {
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }

        return $data;
    }

    public static function present(Venue $venue): array
    {
        return [
            'id' => $venue->id,
            'name' => $venue->name,
            'address' => $venue->address,
            'timezone' => $venue->timezone,
            'segment' => $venue->segment,
            'cuisine' => $venue->cuisine,
            'market_id' => $venue->market_id,
            'business_day_cutoff' => substr((string) $venue->business_day_cutoff, 0, 5),
            'gst_inclusive_default' => (bool) $venue->gst_inclusive_default,
            'created_at' => $venue->created_at?->toIso8601String(),
        ];
    }
}
