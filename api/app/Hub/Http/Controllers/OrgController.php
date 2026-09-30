<?php
// api/app/Hub/Http/Controllers/OrgController.php
namespace App\Hub\Http\Controllers;

use App\Hub\Http\Problem;
use App\Http\Controllers\Controller;
use App\Models\Org;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrgController extends Controller
{
    /** GET /hub/v1/orgs/{org} (schema: org). The path org must be the X-Hub-Org the request acts for. */
    public function show(Request $request, string $org): JsonResponse
    {
        if ($org !== $request->attributes->get('hub.org_id')) {
            return Problem::response(404, 'org_not_found', 'Organisation not found.');
        }

        $record = Org::findOrFail($org);

        return response()->json(['id' => $record->id, 'name' => $record->name]);
    }
}
