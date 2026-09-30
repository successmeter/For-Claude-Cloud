<?php
// api/tests/Feature/Hub/OutboxTest.php

namespace Tests\Feature\Hub;

use App\Hub\Events\HubEvents;
use App\Hub\Models\WebhookEndpoint;
use App\Hub\Models\WebhookOutboxEntry;
use App\Models\Org;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\HubTokens;
use Tests\TestCase;

/**
 * Design §4.6: every change consumers care about writes an outbox row in the same transaction as
 * the change, one per tool that can see it and has an active endpoint. Ids only.
 */
class OutboxTest extends TestCase
{
    use HubTokens, RefreshesPrivilegedDatabase;

    private Org $org;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openid.forceHttps' => false]);
        WebhookEndpoint::create(['tool' => 'web', 'url' => 'https://web.test/hooks/hub', 'secret' => 'shh']);

        $this->org = $this->makeOrg();
        [$client, $secret] = $this->makeWebClient();
        $this->token = $this->userToken($this->makeMember($this->org, 'owner', mfa: true), $client, $secret, 'competitor-sets:read competitor-sets:write');
    }

    private function send(string $method, string $path, array $body = [], ?string $ifMatch = null)
    {
        $headers = ['X-Hub-Org' => $this->org->id] + ($ifMatch ? ['If-Match' => $ifMatch] : []);

        return $this->withToken($this->token)->withHeaders($headers)->json($method, "/hub/v1/orgs/{$this->org->id}{$path}", $body);
    }

    public function test_set_changes_queue_one_event_each_for_web(): void
    {
        $id = $this->send('POST', '/competitor-sets', ['name' => 'S', 'tools' => ['web']])->json('id');
        $this->send('POST', "/competitor-sets/{$id}/members", ['name' => 'A'], 'W/"1"')->assertCreated();
        $this->send('PATCH', "/competitor-sets/{$id}", ['name' => 'T'], 'W/"2"')->assertOk();

        $rows = WebhookOutboxEntry::orderBy('occurred_at')->get();
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame(['web', 'competitorset.changed', $this->org->id, $id], [$row->tool, $row->event, $row->org_id, $row->entity_id]);
            $this->assertNull($row->delivered_at);
        }
    }

    public function test_sets_not_shared_with_web_produce_no_web_event(): void
    {
        $this->send('POST', '/competitor-sets', ['name' => 'S', 'tools' => ['revenue']])->assertCreated();

        $this->assertSame(0, WebhookOutboxEntry::count());
    }

    public function test_unsharing_with_web_still_tells_web(): void
    {
        $id = $this->send('POST', '/competitor-sets', ['name' => 'S', 'tools' => ['revenue', 'web']])->json('id');
        $this->send('PATCH', "/competitor-sets/{$id}", ['tools' => ['revenue']], 'W/"1"')->assertOk();

        $this->assertSame(2, WebhookOutboxEntry::where('tool', 'web')->count(), 'web must hear that it lost the set');
    }

    public function test_refused_write_leaves_no_event(): void
    {
        $id = $this->send('POST', '/competitor-sets', ['name' => 'S', 'tools' => ['web']])->json('id');
        $before = WebhookOutboxEntry::count();

        $this->send('PATCH', "/competitor-sets/{$id}", ['name' => 'Stale'], 'W/"9"')->assertStatus(412);

        $this->assertSame($before, WebhookOutboxEntry::count());
    }

    public function test_event_rolls_back_with_its_transaction(): void
    {
        try {
            DB::transaction(function () {
                app(HubEvents::class)->record('competitorset.changed', $this->org->id, (string) Str::uuid(), ['web']);
                throw new RuntimeException('change failed after the event was recorded');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, WebhookOutboxEntry::count());
    }

    public function test_no_event_for_a_tool_without_an_active_endpoint(): void
    {
        WebhookEndpoint::query()->update(['active' => false]);

        $this->send('POST', '/competitor-sets', ['name' => 'S', 'tools' => ['web']])->assertCreated();

        $this->assertSame(0, WebhookOutboxEntry::count());
    }

    public function test_tool_link_queues_org_tool_linked(): void
    {
        $this->send('PUT', '/tools/web', ['external_tenant_ref' => 't-1'])->assertOk();

        $row = WebhookOutboxEntry::sole();
        $this->assertSame(['org.tool_linked', $this->org->id, $this->org->id], [$row->event, $row->org_id, $row->entity_id]);
    }

    public function test_endpoint_command_prints_the_secret_once_and_rotates(): void
    {
        WebhookEndpoint::query()->update(['active' => false]);

        $this->artisan('hub:webhook-endpoint', ['tool' => 'web', 'url' => 'https://web.example/hooks/hub'])
            ->expectsOutputToContain('Secret (shown once):')
            ->assertSuccessful();
        $endpoint = WebhookEndpoint::where('active', true)->sole();
        $first = $endpoint->secret;
        $this->assertSame(64, strlen($first));

        $this->artisan('hub:webhook-endpoint', ['tool' => 'web', 'url' => 'https://web.example/hooks/hub', '--rotate' => true])
            ->assertSuccessful();
        $endpoint->refresh();
        $this->assertSame($first, $endpoint->previous_secret);
        $this->assertNotSame($first, $endpoint->secret);

        // Stored encrypted, never in plain text.
        $raw = DB::table('webhook_endpoints')->where('id', $endpoint->id)->value('secret');
        $this->assertStringNotContainsString($endpoint->secret, $raw);
    }
}
