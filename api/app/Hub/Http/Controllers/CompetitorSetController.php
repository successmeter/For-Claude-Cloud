<?php
// api/app/Hub/Http/Controllers/CompetitorSetController.php
namespace App\Hub\Http\Controllers;

use App\Hub\Http\CompetitorSetPayload;
use App\Hub\Http\HubCaller;
use App\Hub\Http\Problem;
use App\Hub\Models\CompetitorSet;
use App\Hub\Services\CompetitorSetService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CompetitorSetController extends Controller
{
    /** GET /hub/v1/orgs/{org}/competitor-sets (schema: competitor-set-list) */
    public function index(Request $request, string $org): Response
    {
        if ($org !== $request->attributes->get('hub.org_id')) {
            return $this->notFound();
        }

        $tool = $this->visibleTool($request);
        $sets = CompetitorSet::query()
            ->when($tool !== null, fn ($q) => $q->whereRaw('? = ANY (tools)', [$tool]))
            ->orderBy('name')->orderBy('id')
            ->get();

        return $this->withEtag($request, CompetitorSetPayload::listEtag($sets), fn () => [
            'sets' => $sets->map(fn (CompetitorSet $s) => CompetitorSetPayload::summary($s))->all(),
        ]);
    }

    /** GET /hub/v1/orgs/{org}/competitor-sets/{set} (schema: competitor-set) */
    public function show(Request $request, string $org, string $set): Response
    {
        $record = $this->findVisible($request, $org, $set);
        if (! $record) {
            return $this->notFound();
        }

        return $this->withEtag($request, CompetitorSetPayload::setEtag($record), fn () => CompetitorSetPayload::full($record));
    }

    /**
     * POST /hub/v1/orgs/{org}/competitor-sets/{set}/activation (schema: activation). Called by a
     * consumer before it serves a benchmark from the set. Tool or user callers; same visibility as
     * reading the set.
     */
    public function activate(Request $request, string $org, string $set, CompetitorSetService $service): Response
    {
        $record = $this->findVisible($request, $org, $set);
        if (! $record) {
            return $this->notFound();
        }

        return response()->json(['activated_at' => $service->activate($record)->activated_at->toIso8601ZuluString()]);
    }

    /**
     * The set, if it is in the org the request acts for (RLS already guarantees that for the row)
     * and visible to the caller. A tool only ever sees sets shared with it; a user sees all of
     * their org's sets.
     */
    protected function findVisible(Request $request, string $org, string $set): ?CompetitorSet
    {
        if ($org !== $request->attributes->get('hub.org_id') || ! Str::isUuid($set)) {
            return null;
        }
        $record = CompetitorSet::find($set);
        $caller = HubCaller::of($request);

        // No HubCaller: a signed-in person in the Revenue app (/api/competitor-sets), who sees every set.
        if (! $record || ($caller?->isToolOnly() && ! $record->isVisibleTo((string) $caller->tool))) {
            return null;
        }

        return $record;
    }

    /** Tool callers are pinned to their own tool; users may filter with ?tool=. */
    protected function visibleTool(Request $request): ?string
    {
        $caller = HubCaller::of($request);

        return $caller?->isToolOnly() ? (string) $caller->tool : $request->query('tool');
    }

    protected function withEtag(Request $request, string $etag, \Closure $body): Response
    {
        $matches = collect(explode(',', (string) $request->header('If-None-Match')))
            ->map(fn ($tag) => trim($tag))
            ->contains($etag);

        if ($matches) {
            return response()->noContent(304)->setEtag(trim($etag, 'W/"'), weak: true);
        }

        return response()->json($body())->setEtag(trim($etag, 'W/"'), weak: true);
    }

    protected function notFound(): Response
    {
        return Problem::response(404, 'not_found', 'Not found.');
    }
}
