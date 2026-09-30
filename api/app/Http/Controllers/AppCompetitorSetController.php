<?php
// api/app/Http/Controllers/AppCompetitorSetController.php
namespace App\Http\Controllers;

use App\Hub\Http\Controllers\CompetitorSetController;
use App\Hub\Http\Controllers\CompetitorSetWriteController;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Competitor sets for the Revenue app (Plan D decision D4): the same sets the Web tool uses through
 * /hub/v1, for a signed-in person. The org comes from X-Hub-Org (the `tenant` middleware) instead
 * of the path, and every call goes to the /hub/v1 controllers, so the rules (roles, If-Match and
 * ETags, the composition lock, tools, owner-with-MFA deletes, webhooks) are the same code.
 */
class AppCompetitorSetController extends Controller
{
    public function __construct(private CompetitorSetController $read, private CompetitorSetWriteController $write) {}

    public function index(Request $request): Response
    {
        return $this->read->index($request, $this->org($request));
    }

    public function show(Request $request, string $set): Response
    {
        return $this->read->show($request, $this->org($request), $set);
    }

    public function store(Request $request): Response
    {
        return $this->write->store($request, $this->org($request));
    }

    public function update(Request $request, string $set): Response
    {
        return $this->write->update($request, $this->org($request), $set);
    }

    public function destroy(Request $request, string $set): Response
    {
        return $this->write->destroy($request, $this->org($request), $set);
    }

    public function storeMember(Request $request, string $set): Response
    {
        return $this->write->storeMember($request, $this->org($request), $set);
    }

    public function updateMember(Request $request, string $set, string $member): Response
    {
        return $this->write->updateMember($request, $this->org($request), $set, $member);
    }

    public function destroyMember(Request $request, string $set, string $member): Response
    {
        return $this->write->destroyMember($request, $this->org($request), $set, $member);
    }

    private function org(Request $request): string
    {
        return $request->attributes->get('hub.org_id');
    }
}
