<?php
// api/tests/Feature/Hub/HubCallerAndOrgsTest.php

namespace Tests\Feature\Hub;

use App\Hub\Models\OrgToolLink;
use App\Models\AuditLogEntry;
use App\Services\Tenancy\TenantContext;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\AssertsContract;
use Tests\Support\HubTokens;
use Tests\TestCase;

class HubCallerAndOrgsTest extends TestCase
{
    use AssertsContract, HubTokens, RefreshesPrivilegedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openid.forceHttps' => false]);
    }

    // --- authentication and scopes -------------------------------------------------------

    public function test_no_token_is_a_401_problem(): void
    {
        $response = $this->get('/hub/v1/me')->assertUnauthorized()->assertHeader('Content-Type', 'application/problem+json');
        $this->assertMatchesContract($response, 'problem');
    }

    public function test_missing_scope_is_a_403_problem(): void
    {
        [$client, $secret] = $this->makeWebClient();
        $token = $this->userToken($this->makeMember($this->makeOrg(), 'owner'), $client, $secret, 'openid');

        $this->withToken($token)->get('/hub/v1/me/orgs')
            ->assertForbidden()
            ->assertJson(['type' => 'https://hub/problems/insufficient_scope']);
    }

    public function test_tool_token_cannot_use_user_endpoints(): void
    {
        [$client, $secret] = $this->makeWebClient();
        $token = $this->toolToken($client, $secret, 'orgs');

        $this->withToken($token)->get('/hub/v1/me/orgs')
            ->assertForbidden()
            ->assertJson(['type' => 'https://hub/problems/user_required']);
    }

    public function test_tool_token_cannot_ask_for_an_id_token(): void
    {
        [$client, $secret] = $this->makeWebClient();

        $this->post('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->id,
            'client_secret' => $secret,
            'scope' => 'openid',
        ])->assertStatus(400)->assertJson(['error' => 'invalid_scope']);
    }

    public function test_tool_token_is_refused_the_write_scope_even_if_granted(): void
    {
        \Illuminate\Support\Facades\Route::get('/hub/v1/_write_probe', fn () => ['ok' => true])
            ->middleware('auth.hub:competitor-sets:write');
        [$client, $secret] = $this->makeWebClient();
        $user = $this->makeMember($this->makeOrg(), 'owner');

        $toolToken = $this->toolToken($client, $secret, 'competitor-sets:write');
        $this->withToken($toolToken)->get('/hub/v1/_write_probe')
            ->assertForbidden()
            ->assertJson(['type' => 'https://hub/problems/insufficient_scope']);

        $userToken = $this->userToken($user, $client, $secret, 'competitor-sets:write');
        $this->withToken($userToken)->get('/hub/v1/_write_probe')->assertOk();
    }

    // --- /me and /me/orgs ---------------------------------------------------------------

    public function test_me_returns_the_public_id(): void
    {
        [$client, $secret] = $this->makeWebClient();
        $user = $this->makeMember($this->makeOrg(), 'viewer');
        $token = $this->userToken($user, $client, $secret, 'openid');

        $response = $this->withToken($token)->get('/hub/v1/me')->assertOk();

        $this->assertMatchesContract($response, 'me');
        $this->assertSame($user->public_id, $response->json('sub'));
    }

    public function test_me_orgs_lists_every_org_of_the_user_and_nothing_else(): void
    {
        [$client, $secret] = $this->makeWebClient();
        $a = $this->makeOrg('Alpha');
        $b = $this->makeOrg('Bravo');
        $c = $this->makeOrg('Charlie');
        $user = $this->makeMember($a, 'owner');
        TenantContext::run($b->id, fn () => \App\Models\Membership::create(['org_id' => $b->id, 'user_id' => $user->id, 'role' => 'viewer']));
        $this->makeMember($c, 'owner'); // someone else's org
        $token = $this->userToken($user, $client, $secret, 'orgs');

        $response = $this->withToken($token)->get('/hub/v1/me/orgs')->assertOk();

        $this->assertMatchesContract($response, 'org-list');
        $this->assertSame([
            ['id' => $a->id, 'name' => 'Alpha', 'role' => 'owner'],
            ['id' => $b->id, 'name' => 'Bravo', 'role' => 'viewer'],
        ], $response->json('orgs'));
    }

    public function test_user_setting_alone_cannot_write_memberships(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeMember($org, 'viewer');

        $this->expectException(\Illuminate\Database\QueryException::class);
        TenantContext::runAsUser($user->id, fn () => \App\Models\Membership::create([
            'org_id' => $org->id, 'user_id' => $user->id, 'role' => 'owner',
        ]));
    }

    // --- /orgs/{org} ---------------------------------------------------------------------

    public function test_member_reads_their_org(): void
    {
        [$client, $secret] = $this->makeWebClient();
        $org = $this->makeOrg('Alpha');
        $token = $this->userToken($this->makeMember($org, 'viewer'), $client, $secret, 'orgs');

        $response = $this->withToken($token)->withHeader('X-Hub-Org', $org->id)->get("/hub/v1/orgs/{$org->id}")->assertOk();

        $this->assertMatchesContract($response, 'org');
        $this->assertSame(['id' => $org->id, 'name' => 'Alpha'], $response->json());
    }

    public function test_path_org_must_match_the_header(): void
    {
        [$client, $secret] = $this->makeWebClient();
        $org = $this->makeOrg();
        $other = $this->makeOrg('Other');
        $token = $this->userToken($this->makeMember($org, 'viewer'), $client, $secret, 'orgs');

        $this->withToken($token)->withHeader('X-Hub-Org', $org->id)->get("/hub/v1/orgs/{$other->id}")->assertNotFound();
    }

    public function test_tool_reaches_only_orgs_that_linked_it(): void
    {
        [$client, $secret] = $this->makeWebClient();
        $linked = $this->makeOrg('Linked');
        $unlinked = $this->makeOrg('Unlinked');
        $this->linkTool($linked);
        $token = $this->toolToken($client, $secret, 'orgs');

        $this->withToken($token)->withHeader('X-Hub-Org', $linked->id)->get("/hub/v1/orgs/{$linked->id}")->assertOk();
        $this->withToken($token)->withHeader('X-Hub-Org', $unlinked->id)->get("/hub/v1/orgs/{$unlinked->id}")->assertNotFound();
    }

    public function test_revoked_tool_link_no_longer_grants_access(): void
    {
        [$client, $secret] = $this->makeWebClient();
        $org = $this->makeOrg();
        $this->linkTool($org);
        TenantContext::run($org->id, fn () => OrgToolLink::query()->update(['revoked_at' => now()]));
        $token = $this->toolToken($client, $secret, 'orgs');

        $this->withToken($token)->withHeader('X-Hub-Org', $org->id)->get("/hub/v1/orgs/{$org->id}")->assertNotFound();
    }

    // --- PUT /orgs/{org}/tools/{tool} ------------------------------------------------------

    private function putLink(string $token, string $orgId, string $tool = 'web', string $ref = 'tenant-1')
    {
        return $this->withToken($token)->withHeader('X-Hub-Org', $orgId)
            ->putJson("/hub/v1/orgs/{$orgId}/tools/{$tool}", ['external_tenant_ref' => $ref]);
    }

    public function test_owner_with_mfa_links_the_tool_idempotently(): void
    {
        [$client, $secret] = $this->makeWebClient();
        $org = $this->makeOrg();
        $token = $this->userToken($this->makeMember($org, 'owner', mfa: true), $client, $secret, 'openid');

        $first = $this->putLink($token, $org->id)->assertOk();
        $this->assertMatchesContract($first, 'tool-link');
        $second = $this->putLink($token, $org->id, ref: 'tenant-2')->assertOk();

        $this->assertSame('tenant-2', $second->json('external_tenant_ref'));
        $this->assertSame($first->json('linked_at'), $second->json('linked_at'));
        $this->assertSame(1, TenantContext::run($org->id, fn () => OrgToolLink::count()));
        $this->assertSame(2, AuditLogEntry::where('action', 'tool.linked')->count());
    }

    public function test_owner_without_mfa_is_refused(): void
    {
        [$client, $secret] = $this->makeWebClient();
        $org = $this->makeOrg();
        $token = $this->userToken($this->makeMember($org, 'owner'), $client, $secret, 'openid');

        $this->putLink($token, $org->id)->assertForbidden()->assertJson(['type' => 'https://hub/problems/mfa_required']);
    }

    public function test_manager_cannot_link(): void
    {
        [$client, $secret] = $this->makeWebClient();
        $org = $this->makeOrg();
        $token = $this->userToken($this->makeMember($org, 'manager', mfa: true), $client, $secret, 'openid');

        $this->putLink($token, $org->id)->assertForbidden();
    }

    public function test_tool_token_cannot_link(): void
    {
        [$client, $secret] = $this->makeWebClient();
        $org = $this->makeOrg();
        $this->linkTool($org);

        $this->putLink($this->toolToken($client, $secret, ''), $org->id)
            ->assertForbidden()
            ->assertJson(['type' => 'https://hub/problems/user_required']);
    }

    public function test_unknown_tool_is_404_and_bad_body_is_422(): void
    {
        [$client, $secret] = $this->makeWebClient();
        $org = $this->makeOrg();
        $token = $this->userToken($this->makeMember($org, 'owner', mfa: true), $client, $secret, 'openid');

        $this->putLink($token, $org->id, tool: 'sms')->assertNotFound();
        $this->putLink($token, $org->id, ref: '')->assertStatus(422)->assertJson(['type' => 'https://hub/problems/validation_failed']);
    }
}
