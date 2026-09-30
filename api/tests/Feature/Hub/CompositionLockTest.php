<?php
// api/tests/Feature/Hub/CompositionLockTest.php

namespace Tests\Feature\Hub;

use App\Hub\Models\CompetitorSet;
use App\Models\AuditLogEntry;
use App\Models\Org;
use App\Services\Tenancy\TenantContext;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\AssertsContract;
use Tests\Support\HubTokens;
use Tests\TestCase;

/**
 * Design §4.7: once a set shared with the Web tool has served a benchmark, removing a member locks
 * further removals for 30 days, so averages before and after a removal cannot be differenced to
 * reveal one competitor's traffic.
 */
class CompositionLockTest extends TestCase
{
    use AssertsContract, HubTokens, RefreshesPrivilegedDatabase;

    private Org $org;

    private string $userToken;

    private string $toolToken;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openid.forceHttps' => false]);
        $this->freezeTime();

        $this->org = $this->makeOrg();
        [$client, $secret] = $this->makeWebClient();
        $this->userToken = $this->userToken($this->makeMember($this->org, 'owner', mfa: true), $client, $secret, 'competitor-sets:read competitor-sets:write');
        $this->linkTool($this->org);
        $this->toolToken = $this->toolToken($client, $secret, 'competitor-sets:read');
    }

    private function send(string $token, string $method, string $path, array $body = [], ?string $ifMatch = null)
    {
        $headers = ['X-Hub-Org' => $this->org->id] + ($ifMatch ? ['If-Match' => $ifMatch] : []);

        return $this->withToken($token)->withHeaders($headers)->json($method, "/hub/v1/orgs/{$this->org->id}/competitor-sets{$path}", $body);
    }

    /** A set with $members members; returns [set id, member ids, version]. */
    private function setWithMembers(array $tools = ['web'], int $members = 3): array
    {
        $response = $this->send($this->userToken, 'POST', '', ['name' => 'S', 'tools' => $tools]);
        $id = $response->json('id');
        $version = 1;
        $ids = [];
        for ($i = 0; $i < $members; $i++) {
            $response = $this->send($this->userToken, 'POST', "/{$id}/members", ['name' => "M{$i}"], 'W/"'.$version.'"');
            $version = $response->json('version');
            $ids[] = $response->json('members.'.$i.'.id');
        }

        return [$id, $ids, $version];
    }

    private function activate(string $id, ?string $token = null)
    {
        return $this->send($token ?? $this->toolToken, 'POST', "/{$id}/activation");
    }

    private function removeMember(string $id, string $member, int $version)
    {
        return $this->send($this->userToken, 'DELETE', "/{$id}/members/{$member}", [], 'W/"'.$version.'"');
    }

    public function test_activation_is_idempotent_and_available_to_the_tool(): void
    {
        [$id] = $this->setWithMembers();

        $first = $this->activate($id)->assertOk();
        $this->assertMatchesContract($first, 'activation');
        $this->travel(5)->minutes();
        $this->assertSame($first->json('activated_at'), $this->activate($id)->assertOk()->json('activated_at'));
        $this->assertSame(1, AuditLogEntry::where('action', 'competitor_set.activated')->count());
    }

    public function test_tool_cannot_activate_a_set_not_shared_with_it(): void
    {
        [$id] = $this->setWithMembers(['revenue']);

        $this->activate($id)->assertNotFound();
    }

    public function test_removals_are_free_before_activation(): void
    {
        [$id, $members, $version] = $this->setWithMembers();

        $version = $this->removeMember($id, $members[0], $version)->assertOk()->json('version');
        $response = $this->removeMember($id, $members[1], $version)->assertOk();
        $this->assertNull($response->json('composition_locked_until'));
    }

    public function test_first_removal_after_activation_locks_for_30_days(): void
    {
        [$id, $members, $version] = $this->setWithMembers();
        $this->activate($id);

        $response = $this->removeMember($id, $members[0], $version)->assertOk();
        $this->assertSame(now()->addDays(30)->toIso8601ZuluString(), $response->json('composition_locked_until'));

        $locked = $this->removeMember($id, $members[1], $response->json('version'))
            ->assertStatus(409)
            ->assertJson(['type' => 'https://hub/problems/composition_locked', 'locked_until' => now()->addDays(30)->toIso8601ZuluString()]);
        $this->assertMatchesContract($locked, 'problem');
    }

    public function test_lock_expires_after_exactly_30_days(): void
    {
        [$id, $members, $version] = $this->setWithMembers();
        $this->activate($id);
        $version = $this->removeMember($id, $members[0], $version)->json('version');

        $this->travel(30)->days();
        $this->travel(-1)->seconds();
        $this->removeMember($id, $members[1], $version)->assertStatus(409);

        $this->travel(1)->seconds();
        $this->removeMember($id, $members[1], $version)->assertOk();
    }

    public function test_additions_and_renames_are_always_allowed_while_locked(): void
    {
        [$id, $members, $version] = $this->setWithMembers();
        $this->activate($id);
        $version = $this->removeMember($id, $members[0], $version)->json('version');

        $version = $this->send($this->userToken, 'POST', "/{$id}/members", ['name' => 'New'], 'W/"'.$version.'"')->assertCreated()->json('version');
        $this->send($this->userToken, 'PATCH', "/{$id}", ['name' => 'Renamed'], 'W/"'.$version.'"')->assertOk();
    }

    public function test_unsharing_with_web_or_deleting_is_refused_while_locked(): void
    {
        [$id, $members, $version] = $this->setWithMembers(['revenue', 'web']);
        $this->activate($id);
        $version = $this->removeMember($id, $members[0], $version)->json('version');

        $this->send($this->userToken, 'PATCH', "/{$id}", ['tools' => ['revenue']], 'W/"'.$version.'"')->assertStatus(409);
        $this->send($this->userToken, 'DELETE', "/{$id}")->assertStatus(409);

        $this->assertSame(['revenue', 'web'], TenantContext::run($this->org->id, fn () => CompetitorSet::find($id)->tools));
    }

    public function test_sets_not_shared_with_web_are_never_locked(): void
    {
        [$id, $members, $version] = $this->setWithMembers(['revenue']);
        TenantContext::run($this->org->id, fn () => CompetitorSet::whereKey($id)->update(['activated_at' => now()]));

        $response = $this->removeMember($id, $members[0], $version)->assertOk();
        $this->assertNull($response->json('composition_locked_until'));
        $this->removeMember($id, $members[1], $response->json('version'))->assertOk();
    }
}
