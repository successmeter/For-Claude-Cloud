<?php
// api/tests/Feature/Hub/CompetitorSetWriteTest.php

namespace Tests\Feature\Hub;

use App\Hub\Models\CompetitorSet;
use App\Hub\Models\CompetitorSetMember;
use App\Models\AuditLogEntry;
use App\Models\Org;
use App\Services\Tenancy\TenantContext;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\AssertsContract;
use Tests\Support\HubTokens;
use Tests\TestCase;

class CompetitorSetWriteTest extends TestCase
{
    use AssertsContract, HubTokens, RefreshesPrivilegedDatabase;

    private Org $org;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openid.forceHttps' => false]);
        $this->org = $this->makeOrg();
    }

    private function tokenFor(string $role, bool $mfa = false): string
    {
        [$client, $secret] = $this->makeWebClient();

        return $this->userToken($this->makeMember($this->org, $role, $mfa), $client, $secret, 'competitor-sets:read competitor-sets:write');
    }

    private function send(string $token, string $method, string $path, array $body = [], ?string $ifMatch = null)
    {
        $headers = ['X-Hub-Org' => $this->org->id];
        if ($ifMatch !== null) {
            $headers['If-Match'] = $ifMatch;
        }

        return $this->withToken($token)->withHeaders($headers)->json($method, "/hub/v1/orgs/{$this->org->id}/competitor-sets{$path}", $body);
    }

    private function createSet(string $token, array $body = ['name' => 'Leederville', 'tools' => ['web']])
    {
        return $this->send($token, 'POST', '', $body);
    }

    // --- create -----------------------------------------------------------------------------

    public function test_manager_creates_a_set(): void
    {
        $response = $this->createSet($this->tokenFor('manager'))->assertCreated();

        $this->assertMatchesContract($response, 'competitor-set');
        $this->assertSame(['name' => 'Leederville', 'tools' => ['web'], 'version' => 1, 'members' => []],
            array_intersect_key($response->json(), array_flip(['name', 'tools', 'version', 'members'])));
        $this->assertSame('W/"1"', $response->headers->get('ETag'));
        $this->assertSame(1, AuditLogEntry::where('action', 'competitor_set.created')->where('org_id', $this->org->id)->count());
    }

    public function test_viewer_cannot_write(): void
    {
        $this->createSet($this->tokenFor('viewer'))->assertForbidden()->assertJson(['type' => 'https://hub/problems/forbidden']);
    }

    public function test_tool_token_cannot_write(): void
    {
        [$client, $secret] = $this->makeWebClient();
        $this->linkTool($this->org);
        $token = $this->toolToken($client, $secret, 'competitor-sets:read competitor-sets:write');

        $this->createSet($token)->assertForbidden();
    }

    public function test_invalid_bodies_are_422(): void
    {
        $token = $this->tokenFor('owner');

        foreach ([
            ['name' => '', 'tools' => ['web']],
            ['name' => 'X', 'tools' => []],
            ['name' => 'X', 'tools' => ['sms']],
            ['name' => 'X', 'tools' => ['web', 'web']],
            ['name' => str_repeat('x', 121), 'tools' => ['web']],
        ] as $body) {
            $this->createSet($token, $body)->assertStatus(422)->assertJson(['type' => 'https://hub/problems/validation_failed']);
        }
    }

    // --- update / optimistic concurrency ----------------------------------------------------

    public function test_patch_needs_if_match_and_bumps_the_version(): void
    {
        $token = $this->tokenFor('manager');
        $id = $this->createSet($token)->json('id');

        $this->send($token, 'PATCH', "/{$id}", ['name' => 'Renamed'])
            ->assertStatus(428)->assertJson(['type' => 'https://hub/problems/precondition_required']);

        $response = $this->send($token, 'PATCH', "/{$id}", ['name' => 'Renamed'], 'W/"1"')->assertOk();
        $this->assertMatchesContract($response, 'competitor-set');
        $this->assertSame(['Renamed', 2], [$response->json('name'), $response->json('version')]);

        $this->send($token, 'PATCH', "/{$id}", ['name' => 'Stale'], 'W/"1"')
            ->assertStatus(412)->assertJson(['type' => 'https://hub/problems/version_mismatch']);
        $this->assertSame('Renamed', TenantContext::run($this->org->id, fn () => CompetitorSet::find($id)->name));
    }

    // --- members ----------------------------------------------------------------------------

    public function test_member_add_update_remove(): void
    {
        $token = $this->tokenFor('manager');
        $id = $this->createSet($token)->json('id');

        $added = $this->send($token, 'POST', "/{$id}/members", [
            'name' => '  Cafe A  ', 'website_url' => 'https://a.example', 'location_text' => 'Leederville', 'cuisine' => 'cafe',
        ], 'W/"1"')->assertCreated();
        $this->assertMatchesContract($added, 'competitor-set');
        $this->assertSame('Cafe A', $added->json('members.0.name'), 'strings are trimmed');
        $memberId = $added->json('members.0.id');

        $updated = $this->send($token, 'PATCH', "/{$id}/members/{$memberId}", ['cuisine' => 'bakery'], 'W/"2"')->assertOk();
        $this->assertSame('bakery', $updated->json('members.0.cuisine'));

        $removed = $this->send($token, 'DELETE', "/{$id}/members/{$memberId}", [], 'W/"3"')->assertOk();
        $this->assertSame([], $removed->json('members'));
        $this->assertSame(4, $removed->json('version'));

        // Kept for history, marked removed.
        $row = TenantContext::run($this->org->id, fn () => CompetitorSetMember::find($memberId));
        $this->assertNotNull($row->removed_at);

        foreach (['member_added', 'member_updated', 'member_removed'] as $action) {
            $this->assertSame(1, AuditLogEntry::where('action', "competitor_set.{$action}")->count(), $action);
        }
    }

    public function test_member_urls_must_be_http(): void
    {
        $token = $this->tokenFor('manager');
        $id = $this->createSet($token)->json('id');

        $this->send($token, 'POST', "/{$id}/members", ['name' => 'X', 'website_url' => 'javascript:alert(1)'], 'W/"1"')->assertStatus(422);
    }

    public function test_member_of_another_set_is_404(): void
    {
        $token = $this->tokenFor('manager');
        $first = $this->createSet($token)->json('id');
        $second = $this->createSet($token, ['name' => 'Other', 'tools' => ['web']])->json('id');
        $memberId = $this->send($token, 'POST', "/{$first}/members", ['name' => 'A'], 'W/"1"')->json('members.0.id');

        $this->send($token, 'DELETE', "/{$second}/members/{$memberId}", [], 'W/"1"')->assertNotFound();
    }

    public function test_audit_meta_carries_no_names(): void
    {
        $token = $this->tokenFor('manager');
        $id = $this->createSet($token, ['name' => 'Secret Set Name', 'tools' => ['web']])->json('id');
        $this->send($token, 'POST', "/{$id}/members", ['name' => 'Secret Cafe'], 'W/"1"')->assertCreated();

        $meta = AuditLogEntry::where('action', 'like', 'competitor_set.%')->pluck('meta')->toJson();
        $this->assertStringNotContainsString('Secret', $meta);
    }

    // --- delete ------------------------------------------------------------------------------

    public function test_only_an_owner_with_mfa_deletes_a_set(): void
    {
        $managerToken = $this->tokenFor('manager');
        $id = $this->createSet($managerToken)->json('id');

        $this->send($managerToken, 'DELETE', "/{$id}")->assertForbidden();
        $this->send($this->tokenFor('owner'), 'DELETE', "/{$id}")->assertForbidden()->assertJson(['type' => 'https://hub/problems/mfa_required']);
        $this->send($this->tokenFor('owner', mfa: true), 'DELETE', "/{$id}")->assertNoContent();

        $this->send($managerToken, 'GET', "/{$id}")->assertNotFound();
    }
}
