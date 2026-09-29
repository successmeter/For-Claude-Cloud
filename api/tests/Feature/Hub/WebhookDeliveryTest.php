<?php
// api/tests/Feature/Hub/WebhookDeliveryTest.php

namespace Tests\Feature\Hub;

use App\Hub\Events\HubEvents;
use App\Hub\Jobs\DeliverWebhook;
use App\Hub\Models\WebhookEndpoint;
use App\Hub\Models\WebhookOutboxEntry;
use App\Models\Org;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\AssertsContract;
use Tests\Support\HubTokens;
use Tests\TestCase;

class WebhookDeliveryTest extends TestCase
{
    use AssertsContract, HubTokens, RefreshesPrivilegedDatabase;

    private const URL = 'https://web.test/hooks/hub';

    private const SECRET = 'current-secret';

    private Org $org;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openid.forceHttps' => false]);
        $this->freezeTime();
        WebhookEndpoint::create(['tool' => 'web', 'url' => self::URL, 'secret' => self::SECRET]);
        $this->org = $this->makeOrg();
    }

    /**
     * An outbox row as HubEvents writes it, inserted directly so no delivery is dispatched: these
     * tests run the job themselves. (Http::fake stubs stack and the first match wins, so faking a
     * response here just to absorb a dispatch would shadow each test's own fake.)
     */
    private function queueEvent(): WebhookOutboxEntry
    {
        return WebhookOutboxEntry::create([
            'tool' => 'web',
            'event' => HubEvents::COMPETITOR_SET_CHANGED,
            'org_id' => $this->org->id,
            'entity_id' => (string) Str::uuid(),
            'occurred_at' => now(),
            'next_attempt_at' => now(),
        ])->refresh();
    }

    private static function expectedSignature(string $secret, string $timestamp, string $body): string
    {
        return 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    public function test_delivers_a_signed_schema_valid_event(): void
    {
        $entry = $this->queueEvent();
        Http::fake([self::URL => Http::response('', 204)]);

        (new DeliverWebhook($entry->id))->handle();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($entry) {
            $body = $request->body();
            $this->assertMatchesContract(json_decode($body, true), 'webhook-event');

            return $request->url() === self::URL
                && $request->header('Hub-Event-Id')[0] === $entry->id
                && $request->header('Hub-Timestamp')[0] === (string) now()->timestamp
                && $request->header('Hub-Signature')[0] === self::expectedSignature(self::SECRET, (string) now()->timestamp, $body)
                && $request->header('Content-Type')[0] === 'application/json';
        });
        $this->assertNotNull($entry->refresh()->delivered_at);
    }

    public function test_rotation_sends_both_signatures(): void
    {
        WebhookEndpoint::query()->update(['secret' => encrypt('new-secret', false), 'previous_secret' => encrypt(self::SECRET, false)]);
        $entry = $this->queueEvent();
        Http::fake([self::URL => Http::response('', 200)]);

        (new DeliverWebhook($entry->id))->handle();

        Http::assertSent(function (Request $request) {
            $ts = $request->header('Hub-Timestamp')[0];

            return $request->header('Hub-Signature')[0] === self::expectedSignature('new-secret', $ts, $request->body())
                .', '.self::expectedSignature(self::SECRET, $ts, $request->body());
        });
    }

    public function test_failure_is_retried_with_backoff_and_no_response_body_is_kept(): void
    {
        $entry = $this->queueEvent();
        Http::fake([self::URL => Http::response('secret internal detail', 500)]);

        (new DeliverWebhook($entry->id))->handle();
        $entry->refresh();
        $this->assertSame(1, $entry->attempts);
        $this->assertSame('http_500', $entry->last_error);
        $this->assertSame(now()->addMinutes(2)->timestamp, $entry->next_attempt_at->timestamp);
        $this->assertNull($entry->delivered_at);

        // Not due yet: a second run does nothing.
        (new DeliverWebhook($entry->id))->handle();
        $this->assertSame(1, $entry->refresh()->attempts);

        $this->travel(2)->minutes();
        (new DeliverWebhook($entry->id))->handle();
        $entry->refresh();
        $this->assertSame(2, $entry->attempts);
        $this->assertSame(now()->addMinutes(4)->timestamp, $entry->next_attempt_at->timestamp);
    }

    public function test_connection_errors_record_the_exception_class_only(): void
    {
        $entry = $this->queueEvent();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 7: tcp://10.0.0.5 refused'));

        (new DeliverWebhook($entry->id))->handle();

        $this->assertSame('ConnectionException', $entry->refresh()->last_error);
    }

    public function test_gives_up_after_24_hours(): void
    {
        $entry = $this->queueEvent();
        Http::fake([self::URL => Http::response('', 503)]);

        $this->travel(24)->hours();
        $this->travel(1)->seconds();
        (new DeliverWebhook($entry->id))->handle();

        $entry->refresh();
        $this->assertNotNull($entry->failed_at);
        $this->assertNull($entry->delivered_at);
    }

    public function test_delivered_events_are_never_sent_again(): void
    {
        $entry = $this->queueEvent();
        Http::fake([self::URL => Http::response('', 200)]);

        (new DeliverWebhook($entry->id))->handle();
        (new DeliverWebhook($entry->id))->handle();

        Http::assertSentCount(1);
    }

    public function test_sweeper_sends_only_due_events(): void
    {
        $due = $this->queueEvent();
        $later = $this->queueEvent();
        $later->forceFill(['next_attempt_at' => now()->addHour()])->save();
        Http::fake([self::URL => Http::response('', 200)]);

        $this->artisan('hub:deliver-webhooks')->assertSuccessful();

        $this->assertNotNull($due->refresh()->delivered_at);
        $this->assertNull($later->refresh()->delivered_at);
    }

    public function test_a_real_write_is_delivered_after_it_commits_without_tenant_context(): void
    {
        [$client, $secret] = $this->makeWebClient();
        $token = $this->userToken($this->makeMember($this->org, 'owner'), $client, $secret, 'competitor-sets:write');
        $seenTenant = 'unset';
        Http::fake(function () use (&$seenTenant) {
            $seenTenant = TenantContext::current();

            return Http::response('', 200);
        });

        $this->withToken($token)->withHeader('X-Hub-Org', $this->org->id)
            ->postJson("/hub/v1/orgs/{$this->org->id}/competitor-sets", ['name' => 'S', 'tools' => ['web']])
            ->assertCreated();

        $this->assertNotNull(WebhookOutboxEntry::sole()->delivered_at);
        $this->assertNull($seenTenant, 'delivery must run outside any tenant context');
    }
}
