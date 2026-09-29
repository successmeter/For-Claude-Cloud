<?php
// api/app/Hub/Jobs/DeliverWebhook.php
namespace App\Hub\Jobs;

use App\Hub\Models\WebhookEndpoint;
use App\Hub\Models\WebhookOutboxEntry;
use App\Hub\Webhooks\WebhookSigner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one outbox entry (design §4.6). Not tenant-scoped: it reads only the outbox and the
 * endpoint, and never runs inside a tenant transaction.
 *
 * The entry is claimed with a short lease (a conditional UPDATE moving next_attempt_at forward) in
 * its own statement, and the HTTP call happens after, outside any transaction. Two workers can
 * never both claim it, and no row lock is held during the call.
 *
 * Retries back off 2, 4, 8 ... minutes (at most 6 hours); after 24 hours the entry is marked failed.
 * Only the status code or exception class is kept, never a response body or error message.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, Queueable;

    private const LEASE_SECONDS = 60;

    private const GIVE_UP_AFTER_HOURS = 24;

    public function __construct(public string $outboxId) {}

    public function handle(): void
    {
        if (! $this->claim()) {
            return; // delivered, failed, not due yet, or another worker has it
        }

        $entry = WebhookOutboxEntry::findOrFail($this->outboxId);
        $endpoint = WebhookEndpoint::where('tool', $entry->tool)->where('active', true)->first();

        if ($entry->occurred_at->lt(now()->subHours(self::GIVE_UP_AFTER_HOURS))) {
            $this->fail($entry, 'expired');

            return;
        }
        if (! $endpoint) {
            $this->retry($entry, 'no_endpoint');

            return;
        }

        $body = json_encode($entry->payload(), JSON_UNESCAPED_SLASHES);
        $headers = app(WebhookSigner::class)->headers($endpoint, $entry->id, $body, now()->timestamp);

        try {
            $response = Http::timeout(5)->withHeaders($headers)->withBody($body, 'application/json')->post($endpoint->url);
            $error = $response->successful() ? null : 'http_'.$response->status();
        } catch (Throwable $e) {
            $error = class_basename($e);
        }

        if ($error === null) {
            $entry->forceFill(['delivered_at' => now(), 'attempts' => $entry->attempts + 1, 'last_error' => null])->save();
        } else {
            $this->retry($entry, $error);
        }
    }

    private function claim(): bool
    {
        return DB::table('webhook_outbox')
            ->where('id', $this->outboxId)
            ->whereNull('delivered_at')
            ->whereNull('failed_at')
            ->where('next_attempt_at', '<=', now())
            ->update(['next_attempt_at' => now()->addSeconds(self::LEASE_SECONDS)]) === 1;
    }

    private function retry(WebhookOutboxEntry $entry, string $error): void
    {
        $attempts = $entry->attempts + 1;
        $entry->forceFill([
            'attempts' => $attempts,
            'last_error' => $error,
            'next_attempt_at' => now()->addMinutes(min(2 ** $attempts, 360)),
        ])->save();
    }

    private function fail(WebhookOutboxEntry $entry, string $error): void
    {
        $entry->forceFill(['failed_at' => now(), 'last_error' => $entry->last_error ?? $error])->save();
        Log::warning('Hub webhook gave up', ['event_id' => $entry->id, 'tool' => $entry->tool, 'last_error' => $entry->last_error]);
    }
}
