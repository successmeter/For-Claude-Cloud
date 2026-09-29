<?php
// api/app/Http/Controllers/Uploads/UploadController.php
namespace App\Http\Controllers\Uploads;

use App\Hub\Exceptions\HubProblem;
use App\Http\Controllers\Concerns\ChecksOrgRole;
use App\Http\Controllers\Controller;
use App\Ingest\Upload\Mapping;
use App\Ingest\Upload\MappingInvalid;
use App\Ingest\Upload\UploadInfected;
use App\Ingest\Upload\UploadIntake;
use App\Ingest\Upload\UploadPreviewService;
use App\Models\Venue;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Upload runs (Plan C design §5.2). Owners and managers only. */
class UploadController extends Controller
{
    use ChecksOrgRole;

    public function __construct(private AuditLogger $audit) {}

    /** File + mapping -> a previewed run. */
    public function store(Request $request, Venue $venue, UploadIntake $intake, UploadPreviewService $previews): JsonResponse
    {
        $this->requireWriter($request);

        try {
            $file = $intake->receive($request);
        } catch (UploadInfected $e) {
            return InspectUploadController::rejected($this->audit, $venue, $e);
        }

        try {
            $mapping = Mapping::fromInput($request->only(['date_column', 'date_format', 'revenue_column', 'tx_count_column', 'gst_inclusive']), $file->table);
        } catch (MappingInvalid $e) {
            throw new HubProblem(422, 'mapping_invalid', $e->getMessage());
        }

        $run = $previews->preview($venue, $file, $mapping, $request->user());
        $this->audit->record('upload.received', 'ingestion_run', $run->id, $run->org_id, [
            'venue_id' => $venue->id, 'file_sha256' => $run->file_sha256,
            'rows' => $run->summary['rows'], 'problems' => $run->summary['problems'],
        ]);

        return response()->json($run->present(), 201);
    }
}
