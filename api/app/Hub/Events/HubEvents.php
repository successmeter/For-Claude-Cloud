<?php
// api/app/Hub/Events/HubEvents.php
namespace App\Hub\Events;

use App\Hub\Jobs\DeliverWebhook;
use App\Hub\Models\WebhookEndpoint;
use App\Hub\Models\WebhookOutboxEntry;

/**
 * Writes webhook events to the outbox on the current connection, so they commit or roll back with
 * the change they announce (design §4.6). One row per tool in $tools that has an active endpoint,
 * then a delivery job queued for after the commit.
 */
class HubEvents
{
    public const COMPETITOR_SET_CHANGED = 'competitorset.changed';

    public const ORG_TOOL_LINKED = 'org.tool_linked';

    public function record(string $event, string $orgId, string $entityId, array $tools): void
    {
        $tools = WebhookEndpoint::where('active', true)->whereIn('tool', array_unique($tools))->pluck('tool');

        foreach ($tools as $tool) {
            $entry = WebhookOutboxEntry::create([
                'tool' => $tool,
                'event' => $event,
                'org_id' => $orgId,
                'entity_id' => $entityId,
                'occurred_at' => now(),
                'next_attempt_at' => now(),
            ]);

            // Only once the change (and this row) has committed; if the dispatch is lost, the
            // scheduled hub:deliver-webhooks sweep picks the row up.
            DeliverWebhook::dispatch($entry->id)->afterCommit();
        }
    }
}
