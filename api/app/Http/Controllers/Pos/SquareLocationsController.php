<?php
// api/app/Http/Controllers/Pos/SquareLocationsController.php
namespace App\Http\Controllers\Pos;

use App\Hub\Exceptions\HubProblem;
use App\Hub\Http\Problem;
use App\Http\Controllers\Concerns\ChecksOrgRole;
use App\Http\Controllers\Controller;
use App\Models\Venue;
use App\Pos\Square\SquareAuthFailed;
use App\Pos\Square\SquareClient;
use App\Pos\Square\SquareUnavailable;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Square locations and their venues (Plan E design §3 Locations): owners and managers list them;
 * owners with MFA link a location to one venue or unlink it. Square trouble is answered, not thrown,
 * so a `needs_reauth` mark survives the request's transaction.
 */
class SquareLocationsController extends Controller
{
    use ChecksOrgRole;

    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->requireWriter($request);
        $client = SquareClient::forOrg() ?? throw HubProblem::notFound();

        try {
            $locations = $client->locations();
        } catch (SquareAuthFailed) {
            return Problem::response(409, 'square_needs_reauth', 'Square needs the owner to reconnect.');
        } catch (SquareUnavailable) {
            return Problem::response(503, 'square_unavailable', 'Square is not answering. Try again in a few minutes.');
        }

        $links = DB::table('pos_location_links')->where('connection_id', $client->connectionId())->get()->keyBy('location_id');
        $known = array_column($locations, 'id');

        return response()->json(['data' => [
            ...array_map(fn ($l) => $l + ['venue_id' => $links->get($l['id'])?->venue_id], $locations),
            // A linked location Square no longer lists (closed or removed) stays visible, so it can be unlinked.
            ...$links->reject(fn ($link) => in_array($link->location_id, $known, true))->map(fn ($link) => [
                'id' => $link->location_id, 'name' => $link->location_name, 'timezone' => null, 'status' => 'MISSING', 'venue_id' => $link->venue_id,
            ])->values()->all(),
        ]]);
    }

    public function update(Request $request, string $location): JsonResponse
    {
        $this->requireRole($request, 'owner');
        $input = $request->validate(['venue_id' => ['present', 'nullable', 'uuid']]);
        $client = SquareClient::forOrg() ?? throw HubProblem::notFound();
        $connectionId = $client->connectionId();

        if ($input['venue_id'] === null) {
            $deleted = DB::table('pos_location_links')->where('connection_id', $connectionId)->where('location_id', $location)->delete();
            if ($deleted > 0) {
                $this->audit->record('pos.location_unlinked', 'pos_connection', $connectionId, $this->orgId($request), ['location_id' => $location]);
            }

            return response()->json(['id' => $location, 'venue_id' => null]);
        }

        $venue = Venue::find($input['venue_id']) ?? throw new HubProblem(422, 'venue_not_found', 'That venue is not in this organisation.');
        try {
            $found = collect($client->locations())->firstWhere('id', $location);
        } catch (SquareAuthFailed) {
            return Problem::response(409, 'square_needs_reauth', 'Square needs the owner to reconnect.');
        } catch (SquareUnavailable) {
            return Problem::response(503, 'square_unavailable', 'Square is not answering. Try again in a few minutes.');
        }
        if ($found === null) {
            throw HubProblem::notFound();
        }
        $taken = DB::table('pos_location_links')->where('venue_id', $venue->id)->where('location_id', '!=', $location)->exists();
        if ($taken) {
            throw new HubProblem(409, 'venue_already_linked', 'That venue is already linked to another Square location.');
        }

        DB::table('pos_location_links')->upsert([
            'org_id' => $venue->org_id, 'connection_id' => $connectionId, 'location_id' => $location, 'location_name' => $found['name'],
            'venue_id' => $venue->id, 'linked_by' => $request->user()->id, 'created_at' => now(),
        ], ['connection_id', 'location_id'], ['location_name', 'venue_id', 'linked_by']);
        $this->audit->record('pos.location_linked', 'pos_connection', $connectionId, $venue->org_id, ['location_id' => $location, 'venue_id' => $venue->id]);

        return response()->json(['id' => $location, 'venue_id' => $venue->id]);
    }
}
