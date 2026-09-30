<?php
// api/tests/Feature/Hub/AppCompetitorSetParityTest.php

namespace Tests\Feature\Hub;

use App\Models\AuditLogEntry;
use App\Models\Org;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\AssertsContract;
use Tests\Support\HubTokens;
use Tests\TestCase;

/**
 * Plan D Task 6: the Revenue app's /api/competitor-sets (signed-in person, org from X-Hub-Org) behaves
 * exactly like /hub/v1/orgs/{org}/competitor-sets (bearer token). One scenario runs against both;
 * the recorded statuses, ETags and bodies must match once ids and times are normalised.
 */
class AppCompetitorSetParityTest extends TestCase
{
    use AssertsContract, HubTokens, RefreshesPrivilegedDatabase;

    private const ROLES = ['owner' => ['owner', false], 'owner_mfa' => ['owner', true], 'manager' => ['manager', false], 'viewer' => ['viewer', false]];

    protected function setUp(): void
    {
        parent::setUp();
        config(['openid.forceHttps' => false]);
        $this->freezeTime();
    }

    /** @return array<string, User> */
    private function people(Org $org): array
    {
        return array_map(fn ($r) => $this->makeMember($org, $r[0], $r[1]), self::ROLES);
    }

    private function hubTransport(Org $org): \Closure
    {
        [$client, $secret] = $this->makeWebClient();
        $tokens = array_map(fn (User $u) => $this->userToken($u, $client, $secret, 'competitor-sets:read competitor-sets:write'), $this->people($org));

        return fn (string $who, string $method, string $path, array $body, array $headers) => $this->withToken($tokens[$who])
            ->json($method, "/hub/v1/orgs/{$org->id}/competitor-sets{$path}", $body, ['X-Hub-Org' => $org->id] + $headers);
    }

    private function appTransport(Org $org): \Closure
    {
        $people = $this->people($org);

        return function (string $who, string $method, string $path, array $body, array $headers) use ($org, $people) {
            Auth::forgetGuards();
            $this->flushHeaders();

            return $this->actingAs($people[$who])->json($method, "/api/competitor-sets{$path}", $body, ['X-Hub-Org' => $org->id] + $headers);
        };
    }

    /** @return list<array{string, int, ?string, mixed}> [step, status, etag, body] */
    private function scenario(\Closure $call, bool $checkContract): array
    {
        $log = [];
        $step = function (string $name, string $who, string $method, string $path = '', array $body = [], array $headers = []) use ($call, &$log, $checkContract): TestResponse {
            $response = $call($who, $method, $path, $body, $headers);
            if ($checkContract && in_array($response->status(), [200, 201], true)) {
                $this->assertMatchesContract($response, str_starts_with($name, 'list') ? 'competitor-set-list' : 'competitor-set');
            }
            $etag = preg_replace('/^W\/"[0-9a-f]{40}"$/', 'H', (string) $response->headers->get('ETag'));
            $log[] = [$name, $response->status(), $etag, $response->getContent() === '' ? null : $response->json()];

            return $response;
        };

        $id = $step('create', 'manager', 'POST', '', ['name' => 'Leederville', 'tools' => ['revenue', 'web']])->json('id');
        $step('viewer cannot create', 'viewer', 'POST', '', ['name' => 'X', 'tools' => ['web']]);
        $step('invalid', 'manager', 'POST', '', ['name' => '', 'tools' => ['sms']]);
        $step('list', 'viewer', 'GET');
        $step('list for web', 'viewer', 'GET', '?tool=web');
        $step('show', 'viewer', 'GET', "/{$id}");
        $step('not modified', 'viewer', 'GET', "/{$id}", [], ['If-None-Match' => 'W/"1"']);
        $step('rename without If-Match', 'manager', 'PATCH', "/{$id}", ['name' => 'Renamed']);
        $step('rename', 'manager', 'PATCH', "/{$id}", ['name' => 'Renamed'], ['If-Match' => 'W/"1"']);
        $step('stale rename', 'manager', 'PATCH', "/{$id}", ['name' => 'Stale'], ['If-Match' => 'W/"1"']);
        $member = $step('add member', 'manager', 'POST', "/{$id}/members", ['name' => ' Cafe A ', 'website_url' => 'https://a.example', 'cuisine' => 'cafe'], ['If-Match' => 'W/"2"'])->json('members.0.id');
        $step('viewer cannot add', 'viewer', 'POST', "/{$id}/members", ['name' => 'B'], ['If-Match' => 'W/"3"']);
        $step('bad url', 'manager', 'POST', "/{$id}/members", ['name' => 'B', 'website_url' => 'javascript:x'], ['If-Match' => 'W/"3"']);
        $step('update member', 'manager', 'PATCH', "/{$id}/members/{$member}", ['cuisine' => 'bakery'], ['If-Match' => 'W/"3"']);
        $step('remove member', 'manager', 'DELETE', "/{$id}/members/{$member}", [], ['If-Match' => 'W/"4"']);
        $step('removed member is gone', 'manager', 'DELETE', "/{$id}/members/{$member}", [], ['If-Match' => 'W/"5"']);
        $step('manager cannot delete', 'manager', 'DELETE', "/{$id}");
        $step('owner needs MFA to delete', 'owner', 'DELETE', "/{$id}");
        $step('owner with MFA deletes', 'owner_mfa', 'DELETE', "/{$id}");
        $step('deleted', 'manager', 'GET', "/{$id}");
        $step('not a uuid', 'manager', 'GET', '/not-a-uuid');

        return $log;
    }

    /** Ids become id1, id2... in order of appearance; times become T; list ETags (a hash over ids) become H. */
    private function normalise(array $log): array
    {
        $ids = [];
        $json = json_encode($log);
        $json = preg_replace('/\d{4}-\d{2}-\d{2}T[0-9:.]+(Z|[+-]\d{2}:\d{2})/', 'T', $json);

        return json_decode(preg_replace_callback('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', function ($m) use (&$ids) {
            return $ids[$m[0]] ??= 'id'.(count($ids) + 1);
        }, $json), true);
    }

    public function test_the_app_routes_answer_exactly_like_hub_v1(): void
    {
        $hubOrg = $this->makeOrg('Hub caller');
        $appOrg = $this->makeOrg('App caller');

        $hub = $this->normalise($this->scenario($this->hubTransport($hubOrg), checkContract: false));
        $app = $this->normalise($this->scenario($this->appTransport($appOrg), checkContract: true));

        $this->assertSame($hub, $app);
        // The scenario did what it says (so matching logs mean matching behaviour, not matching failures).
        $this->assertSame(
            [201, 403, 422, 200, 200, 200, 304, 428, 200, 412, 201, 403, 422, 200, 200, 404, 403, 403, 204, 404, 404],
            array_column($app, 1),
        );
        $this->assertSame('https://hub/problems/mfa_required', $app[17][3]['type']);

        $actions = fn (Org $org) => AuditLogEntry::where('org_id', $org->id)->orderBy('action')->pluck('action')->all();
        $this->assertSame($actions($hubOrg), $actions($appOrg));
        $this->assertNotEmpty($actions($appOrg));
    }

    public function test_the_app_routes_need_sign_in_and_membership(): void
    {
        $org = $this->makeOrg();
        $stranger = $this->makeMember($this->makeOrg('Other'), 'owner', true);

        $this->getJson('/api/competitor-sets', ['X-Hub-Org' => $org->id])->assertUnauthorized();
        $this->actingAs($stranger)->getJson('/api/competitor-sets', ['X-Hub-Org' => $org->id])->assertNotFound();
        $this->actingAs($stranger)->getJson('/api/competitor-sets')->assertStatus(400);
    }
}
