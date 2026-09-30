<?php
// api/app/Http/Controllers/Uploads/InspectUploadController.php
namespace App\Http\Controllers\Uploads;

use App\Hub\Http\Problem;
use App\Http\Controllers\Concerns\ChecksOrgRole;
use App\Http\Controllers\Controller;
use App\Ingest\Upload\MappingDetector;
use App\Ingest\Upload\UploadInfected;
use App\Ingest\Upload\UploadIntake;
use App\Models\Venue;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reads a file and proposes a mapping (Plan C design §4.2). Returns the header and the first rows
 * to the user who sent them; nothing is stored or logged.
 */
class InspectUploadController extends Controller
{
    use ChecksOrgRole;

    public function __invoke(Request $request, Venue $venue, UploadIntake $intake, MappingDetector $detector, AuditLogger $audit): JsonResponse
    {
        $this->requireWriter($request);

        try {
            $file = $intake->receive($request);
        } catch (UploadInfected $e) {
            return self::rejected($audit, $venue, $e);
        }

        return response()->json([
            'columns' => $file->table->header,
            'row_count' => count($file->table->rows),
            'sample' => array_slice($file->table->rows, 0, 5),
            'proposal' => $detector->propose($file->table, (bool) $venue->gst_inclusive_default),
        ]);
    }

    /** Answered, not thrown, so the audit row survives the request's transaction. */
    public static function rejected(AuditLogger $audit, Venue $venue, UploadInfected $e): JsonResponse
    {
        $audit->record('upload.rejected_malware', 'venue', $venue->id, $venue->org_id, ['signature' => $e->signature, 'file_sha256' => $e->sha256]);

        return Problem::response(422, 'upload_rejected', $e->getMessage());
    }
}
