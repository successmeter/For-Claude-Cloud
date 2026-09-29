<?php
// api/app/Http/Controllers/Uploads/UploadController.php
namespace App\Http\Controllers\Uploads;

use App\Hub\Exceptions\HubProblem;
use App\Http\Controllers\Concerns\ChecksOrgRole;
use App\Http\Controllers\Controller;
use App\Ingest\Models\IngestionRun;
use App\Ingest\Upload\CommitUpload;
use App\Ingest\Upload\Mapping;
use App\Ingest\Upload\MappingInvalid;
use App\Ingest\Upload\UploadInfected;
use App\Ingest\Upload\UploadIntake;
use App\Ingest\Upload\UploadPreviewService;
use App\Models\Venue;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    public function commit(Request $request, IngestionRun $run, CommitUpload $commits): JsonResponse
    {
        $this->requireWriter($request);

        return response()->json($commits->commit($run)->present());
    }

    public function index(Request $request, Venue $venue): JsonResponse
    {
        $this->requireWriter($request);
        $page = max(1, (int) $request->query('page', 1));
        $query = IngestionRun::where('venue_id', $venue->id);

        return response()->json([
            'data' => (clone $query)->orderByDesc('created_at')->forPage($page, 20)->get()->map->present(),
            'meta' => ['page' => $page, 'per_page' => 20, 'total' => $query->count()],
        ]);
    }

    public function show(Request $request, IngestionRun $run): JsonResponse
    {
        $this->requireWriter($request);

        return response()->json($run->present());
    }

    /** Changed days, old against new, while the run is still a preview. */
    public function changes(Request $request, IngestionRun $run): JsonResponse
    {
        $this->requireWriter($request);
        if ($run->status !== 'previewed') {
            throw new HubProblem(409, 'run_not_previewed', 'Changes are only listed for a preview.');
        }
        $page = max(1, (int) $request->query('page', 1));
        $query = DB::table('ingestion_run_rows as r')
            ->join('sales_daily as s', fn ($j) => $j->on('s.business_date', '=', 'r.business_date')->where('s.venue_id', $run->venue_id))
            ->where('r.run_id', $run->id)->where('r.change', 'changed');

        $rows = (clone $query)->orderBy('r.business_date')->forPage($page, 50)->get([
            'r.business_date', 's.revenue_cents as old_revenue', 's.gst_inclusive as old_gst', 's.tx_count as old_tx',
            'r.revenue_cents as new_revenue', 'r.gst_inclusive as new_gst', 'r.tx_count as new_tx',
        ]);

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'date' => $r->business_date,
                'old' => ['revenue_cents' => (int) $r->old_revenue, 'gst_inclusive' => (bool) $r->old_gst, 'tx_count' => $r->old_tx === null ? null : (int) $r->old_tx],
                'new' => ['revenue_cents' => (int) $r->new_revenue, 'gst_inclusive' => (bool) $r->new_gst, 'tx_count' => $r->new_tx === null ? null : (int) $r->new_tx],
            ]),
            'meta' => ['page' => $page, 'per_page' => 50, 'total' => $query->count()],
        ]);
    }

    public function destroy(Request $request, IngestionRun $run): JsonResponse
    {
        $this->requireWriter($request);
        $run = IngestionRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
        if ($run->status !== 'previewed') {
            throw new HubProblem(409, 'run_not_discardable', 'Only a preview can be discarded.');
        }

        $run->update(['status' => 'discarded']);
        DB::table('ingestion_run_rows')->where('run_id', $run->id)->delete();
        $this->audit->record('upload.discarded', 'ingestion_run', $run->id, $run->org_id);

        return response()->json($run->present());
    }
}
