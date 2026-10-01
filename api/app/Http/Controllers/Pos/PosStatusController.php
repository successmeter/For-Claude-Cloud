<?php
// api/app/Http/Controllers/Pos/PosStatusController.php
namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Pos\Square\SquareOAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** POS connection health for the Data sources screen (Plan E design §5): everyone in the org; never tokens. */
class PosStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $c = DB::table('pos_connections')->where('provider', 'square')->first();
        $square = ['available' => SquareOAuth::configured(), 'status' => 'not_connected'];
        if ($c !== null) {
            $square = [
                'available' => $square['available'],
                'status' => $c->status,
                'merchant_id' => $c->merchant_id,
                'connected_at' => \Carbon\CarbonImmutable::parse($c->created_at)->toIso8601String(),
                'last_synced_at' => $c->last_synced_at === null ? null : \Carbon\CarbonImmutable::parse($c->last_synced_at)->toIso8601String(),
                'last_error' => $c->last_error,
                'paused' => $c->paused_at !== null,
                'locations_linked' => DB::table('pos_location_links')->where('connection_id', $c->id)->count(),
                // Venues whose sales come from Square (their uploads take covers only).
                'venue_ids' => DB::table('pos_location_links')->where('connection_id', $c->id)->orderBy('venue_id')->pluck('venue_id')->all(),
            ];
        }

        return response()->json(['square' => $square]);
    }
}
