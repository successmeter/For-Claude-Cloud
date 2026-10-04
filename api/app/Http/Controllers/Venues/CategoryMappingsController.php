<?php
// api/app/Http/Controllers/Venues/CategoryMappingsController.php
namespace App\Http\Controllers\Venues;

use App\Bench\RecomputeVenue;
use App\Hub\Exceptions\HubProblem;
use App\Http\Controllers\Concerns\ChecksOrgRole;
use App\Http\Controllers\Controller;
use App\Models\Venue;
use App\Pos\CategoryGuesser;
use App\Pos\SplitDeriver;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Which POS categories are food, drinks or other (Plan E design E3, §2.3): owners and managers see
 * every category the venue's POS has reported, with its last 28 days of sales and a guess; owners
 * with MFA save the mapping, which re-derives the split for every POS day and then the metrics.
 */
class CategoryMappingsController extends Controller
{
    use ChecksOrgRole;

    public function __construct(private SplitDeriver $splits, private RecomputeVenue $recompute, private AuditLogger $audit) {}

    public function index(Request $request, Venue $venue): JsonResponse
    {
        $this->requireWriter($request);

        return response()->json($this->present($venue));
    }

    public function update(Request $request, Venue $venue): JsonResponse
    {
        $this->requireRole($request, 'owner');
        $input = $request->validate([
            'mappings' => ['required', 'array', 'min:1', 'max:500'],
            'mappings.*.key' => ['required', 'string', 'max:200', 'distinct'],
            'mappings.*.kind' => ['required', 'in:food,drinks,other'],
        ]);

        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['categories:'.$venue->id]);
        $seen = DB::table('sales_daily_categories')->where('venue_id', $venue->id)->where('source', 'square')
            ->distinct()->pluck('category_key')->flip();
        $current = DB::table('category_mappings')->where('venue_id', $venue->id)->where('source', 'square')->pluck('kind', 'category_key');

        $changed = 0;
        foreach ($input['mappings'] as $m) {
            if (! $seen->has($m['key'])) {
                throw new HubProblem(422, 'unknown_category', 'One of the categories has not been seen in this venue\'s sales.');
            }
            if ($current->get($m['key']) === $m['kind']) {
                continue;
            }
            DB::table('category_mappings')->upsert([
                'org_id' => $venue->org_id, 'venue_id' => $venue->id, 'source' => 'square', 'category_key' => $m['key'],
                'kind' => $m['kind'], 'mapped_by' => $request->user()->id, 'mapped_at' => now(),
            ], ['venue_id', 'source', 'category_key'], ['kind', 'mapped_by', 'mapped_at']);
            $changed++;
        }

        $days = 0;
        if ($changed > 0) {
            $earliest = $this->splits->apply($venue->id);
            if ($earliest !== null) {
                $days = DB::table('sales_daily')->where('venue_id', $venue->id)->where('source', 'pos')->where('business_date', '>=', $earliest)->count();
                $this->recompute->run($venue->id, CarbonImmutable::parse($earliest));
            }
            $this->audit->record('category_mappings.updated', 'venue', $venue->id, $venue->org_id, ['venue_id' => $venue->id, 'changed' => $changed, 'days' => $days]);
        }

        return response()->json($this->present($venue));
    }

    private function present(Venue $venue): array
    {
        $latest = DB::table('sales_daily_categories')->where('venue_id', $venue->id)->where('source', 'square')->max('business_date');
        $since = $latest === null ? null : CarbonImmutable::parse($latest)->subDays(27)->toDateString();

        $rows = DB::select(<<<'SQL'
            SELECT c.category_key AS key,
                   (array_agg(c.category_name ORDER BY c.business_date DESC))[1] AS name,
                   coalesce(sum(c.net_cents) FILTER (WHERE c.business_date >= :since), 0) AS recent_cents,
                   m.kind
            FROM sales_daily_categories c
            LEFT JOIN category_mappings m ON m.venue_id = c.venue_id AND m.source = c.source AND m.category_key = c.category_key
            WHERE c.venue_id = :venue AND c.source = 'square'
            GROUP BY c.category_key, m.kind
            ORDER BY recent_cents DESC, name
        SQL, ['venue' => $venue->id, 'since' => $since ?? '9999-12-31']);

        $data = array_map(fn ($r) => [
            'key' => $r->key,
            'name' => $r->name,
            'kind' => $r->kind,
            'guess' => CategoryGuesser::guess($r->key, $r->name),
            'net_cents_28d' => (int) $r->recent_cents,
            'new' => $r->kind === null,
        ], $rows);

        return [
            'venue_id' => $venue->id,
            'source' => 'square',
            'window' => $since === null ? null : ['from' => $since, 'to' => $latest],
            'unmapped' => count(array_filter($data, fn ($c) => $c['new'])),
            'data' => $data,
        ];
    }
}
