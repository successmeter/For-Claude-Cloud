<?php
// api/app/Hub/Http/Controllers/CompetitorSetWriteController.php
namespace App\Hub\Http\Controllers;

use App\Hub\Exceptions\HubProblem;
use App\Hub\Http\CompetitorSetPayload;
use App\Hub\Models\CompetitorSet;
use App\Hub\Models\CompetitorSetMember;
use App\Hub\Services\CompetitorSetService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Competitor-set writes (design §4.5). Routes require a user token with competitor-sets:write;
 * roles: owner or manager, and only an owner (with MFA) deletes a set. Every change except
 * create and delete needs If-Match with the set's current version.
 */
class CompetitorSetWriteController extends Controller
{
    public function __construct(private CompetitorSetService $service) {}

    public function store(Request $request, string $org): JsonResponse
    {
        $this->authorizeRole($request, $org, ['owner', 'manager']);
        $data = $request->validate($this->setRules(required: true));

        $set = $this->service->create($org, $data['name'], $data['tools'], $request->user());

        return $this->respond($set, 201);
    }

    public function update(Request $request, string $org, string $set): JsonResponse
    {
        $this->authorizeRole($request, $org, ['owner', 'manager']);
        $record = $this->findSet($set);
        $version = $this->ifMatch($request);
        $data = $request->validate($this->setRules(required: false));

        return $this->respond($this->service->update($record, $version, $data));
    }

    public function destroy(Request $request, string $org, string $set): Response
    {
        $this->authorizeRole($request, $org, ['owner']);
        $this->service->delete($this->findSet($set));

        return response()->noContent();
    }

    public function storeMember(Request $request, string $org, string $set): JsonResponse
    {
        $this->authorizeRole($request, $org, ['owner', 'manager']);
        $record = $this->findSet($set);
        $version = $this->ifMatch($request);
        $data = $request->validate($this->memberRules(required: true));

        return $this->respond($this->service->addMember($record, $version, $data), 201);
    }

    public function updateMember(Request $request, string $org, string $set, string $member): JsonResponse
    {
        $this->authorizeRole($request, $org, ['owner', 'manager']);
        $record = $this->findSet($set);
        $row = $this->findMember($record, $member);
        $version = $this->ifMatch($request);
        $data = $request->validate($this->memberRules(required: false));

        return $this->respond($this->service->updateMember($record, $version, $row, $data));
    }

    public function destroyMember(Request $request, string $org, string $set, string $member): JsonResponse
    {
        $this->authorizeRole($request, $org, ['owner', 'manager']);
        $record = $this->findSet($set);
        $row = $this->findMember($record, $member);
        $version = $this->ifMatch($request);

        return $this->respond($this->service->removeMember($record, $version, $row));
    }

    private function authorizeRole(Request $request, string $org, array $roles): void
    {
        if ($org !== $request->attributes->get('hub.org_id')) {
            throw HubProblem::notFound();
        }
        if (! in_array($request->attributes->get('hub.role'), $roles, true)) {
            throw HubProblem::forbidden('Your role cannot change competitor sets.');
        }
    }

    private function findSet(string $id): CompetitorSet
    {
        // RLS limits the lookup to the org the request acts for.
        $set = Str::isUuid($id) ? CompetitorSet::find($id) : null;

        return $set ?? throw HubProblem::notFound();
    }

    private function findMember(CompetitorSet $set, string $id): CompetitorSetMember
    {
        $member = Str::isUuid($id)
            ? CompetitorSetMember::whereKey($id)->where('set_id', $set->id)->whereNull('removed_at')->first()
            : null;

        return $member ?? throw HubProblem::notFound();
    }

    /** Accepts W/"3", "3" or 3. */
    private function ifMatch(Request $request): int
    {
        $raw = trim((string) $request->header('If-Match'));
        if ($raw === '') {
            throw HubProblem::preconditionRequired();
        }
        $value = trim(preg_replace('/^W\//', '', $raw), '"');

        return ctype_digit($value) ? (int) $value : throw HubProblem::versionMismatch();
    }

    private function setRules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'string', 'min:1', 'max:120'],
            'tools' => [$presence, 'array', 'min:1'],
            'tools.*' => ['distinct', Rule::in(['revenue', 'web'])],
        ];
    }

    private function memberRules(bool $required): array
    {
        return [
            'name' => [$required ? 'required' : 'sometimes', 'string', 'min:1', 'max:200'],
            'website_url' => ['sometimes', 'nullable', 'string', 'max:500', 'url:http,https'],
            'location_text' => ['sometimes', 'nullable', 'string', 'max:200'],
            'cuisine' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    private function respond(CompetitorSet $set, int $status = 200): JsonResponse
    {
        return response()->json(CompetitorSetPayload::full($set), $status)
            ->setEtag((string) $set->version, weak: true);
    }
}
