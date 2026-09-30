<?php
// api/tests/Feature/Hub/CompetitorSetReadTest.php

namespace Tests\Feature\Hub;

use App\Hub\Models\CompetitorSet;
use App\Hub\Models\CompetitorSetMember;
use App\Models\Org;
use App\Services\Tenancy\TenantContext;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\AssertsContract;
use Tests\Support\HubTokens;
use Tests\TestCase;

class CompetitorSetReadTest extends TestCase
{
    use AssertsContract, HubTokens, RefreshesPrivilegedDatabase;

    private Org $org;

    private CompetitorSet $shared;

    private CompetitorSet $revenueOnly;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openid.forceHttps' => false]);

        $this->org = $this->makeOrg();
        TenantContext::run($this->org->id, function () {
            $this->shared = CompetitorSet::create(['org_id' => $this->org->id, 'name' => 'Shared', 'tools' => ['revenue', 'web']]);
            $this->revenueOnly = CompetitorSet::create(['org_id' => $this->org->id, 'name' => 'Revenue only', 'tools' => ['revenue']]);
            CompetitorSetMember::create(['org_id' => $this->org->id, 'set_id' => $this->shared->id, 'name' => 'Cafe A', 'website_url' => 'https://a.example', 'location_text' => 'Leederville', 'cuisine' => 'cafe']);
            $removed = CompetitorSetMember::create(['org_id' => $this->org->id, 'set_id' => $this->shared->id, 'name' => 'Gone']);
            $removed->forceFill(['removed_at' => now()])->save();
        });
    }

    private function toolCaller(): string
    {
        [$client, $secret] = $this->makeWebClient();
        $this->linkTool($this->org);

        return $this->toolToken($client, $secret, 'competitor-sets:read');
    }

    private function userCaller(string $role = 'viewer'): string
    {
        [$client, $secret] = $this->makeWebClient();

        return $this->userToken($this->makeMember($this->org, $role), $client, $secret, 'competitor-sets:read');
    }

    private function getAs(string $token, string $path, array $headers = [])
    {
        return $this->withToken($token)->withHeaders(['X-Hub-Org' => $this->org->id] + $headers)->get($path);
    }

    public function test_tool_lists_only_sets_visible_to_it(): void
    {
        $response = $this->getAs($this->toolCaller(), "/hub/v1/orgs/{$this->org->id}/competitor-sets?tool=revenue")->assertOk();

        $this->assertMatchesContract($response, 'competitor-set-list');
        $this->assertSame(['Shared'], array_column($response->json('sets'), 'name'), 'a tool cannot widen its view with ?tool=');
    }

    public function test_user_can_filter_by_tool_or_see_all(): void
    {
        $token = $this->userCaller();

        $all = $this->getAs($token, "/hub/v1/orgs/{$this->org->id}/competitor-sets")->assertOk();
        $this->assertEqualsCanonicalizing(['Shared', 'Revenue only'], array_column($all->json('sets'), 'name'));

        $web = $this->getAs($token, "/hub/v1/orgs/{$this->org->id}/competitor-sets?tool=web")->assertOk();
        $this->assertSame(['Shared'], array_column($web->json('sets'), 'name'));
    }

    public function test_show_returns_current_members_only(): void
    {
        $response = $this->getAs($this->userCaller(), "/hub/v1/orgs/{$this->org->id}/competitor-sets/{$this->shared->id}")->assertOk();

        $this->assertMatchesContract($response, 'competitor-set');
        $this->assertSame(['Cafe A'], array_column($response->json('members'), 'name'));
        $this->assertSame('W/"1"', $response->headers->get('ETag'));
    }

    public function test_removed_members_come_with_dates_and_without_names(): void
    {
        $response = $this->getAs($this->toolCaller(), "/hub/v1/orgs/{$this->org->id}/competitor-sets/{$this->shared->id}")->assertOk();

        $removed = $response->json('removed_members');
        $this->assertCount(1, $removed);
        $this->assertSame(['id', 'added_at', 'removed_at'], array_keys($removed[0]));
        $this->assertStringNotContainsString('Gone', $response->getContent());
        $this->assertNotNull($response->json('members.0.added_at'));
    }

    public function test_hidden_set_is_404_for_the_tool(): void
    {
        $this->getAs($this->toolCaller(), "/hub/v1/orgs/{$this->org->id}/competitor-sets/{$this->revenueOnly->id}")
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_soft_deleted_and_foreign_sets_are_404(): void
    {
        $other = $this->makeOrg('Other');
        $foreign = TenantContext::run($other->id, fn () => CompetitorSet::create(['org_id' => $other->id, 'name' => 'Theirs', 'tools' => ['web']]));
        TenantContext::run($this->org->id, fn () => $this->shared->delete());
        $token = $this->userCaller();

        $this->getAs($token, "/hub/v1/orgs/{$this->org->id}/competitor-sets/{$this->shared->id}")->assertNotFound();
        $this->getAs($token, "/hub/v1/orgs/{$this->org->id}/competitor-sets/{$foreign->id}")->assertNotFound();
        $this->getAs($token, "/hub/v1/orgs/{$this->org->id}/competitor-sets/not-a-uuid")->assertNotFound();
    }

    public function test_etag_round_trip_gives_304(): void
    {
        $token = $this->userCaller();
        $path = "/hub/v1/orgs/{$this->org->id}/competitor-sets";

        $etag = $this->getAs($token, $path)->assertOk()->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->getAs($token, $path, ['If-None-Match' => $etag])->assertStatus(304)->assertNoContent(304);

        $showPath = "{$path}/{$this->shared->id}";
        $this->getAs($token, $showPath, ['If-None-Match' => 'W/"1"'])->assertStatus(304);
        $this->getAs($token, $showPath, ['If-None-Match' => 'W/"0"'])->assertOk();
    }

    public function test_read_scope_is_required(): void
    {
        [$client, $secret] = $this->makeWebClient();
        $token = $this->userToken($this->makeMember($this->org, 'owner'), $client, $secret, 'orgs');

        $this->getAs($token, "/hub/v1/orgs/{$this->org->id}/competitor-sets")
            ->assertForbidden()
            ->assertJson(['type' => 'https://hub/problems/insufficient_scope']);
    }
}
