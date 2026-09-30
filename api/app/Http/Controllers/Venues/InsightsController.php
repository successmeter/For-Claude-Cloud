<?php
// api/app/Http/Controllers/Venues/InsightsController.php
namespace App\Http\Controllers\Venues;

use App\Http\Controllers\Controller;
use App\Models\Venue;
use App\Strategy\Insights\InsightEngine;
use Illuminate\Http\JsonResponse;

/** The venue's latest own-history findings (schemas/findings.v1.json). Everyone in the org may read them. */
class InsightsController extends Controller
{
    public function latest(Venue $venue, InsightEngine $insights): JsonResponse
    {
        return response()->json($insights->latest($venue->id), options: JSON_PRESERVE_ZERO_FRACTION);
    }
}
