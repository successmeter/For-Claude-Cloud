<?php
// api/app/Hub/Events/HubEvents.php
namespace App\Hub\Events;

use App\Hub\Models\WebhookEndpoint;
use App\Hub\Models\WebhookOutboxEntry;

/**
 * Writes webhook events to the outbox on the current connection, so they commit or roll back with
 * the change they announce (design §4.6). One row per tool in $tools that has an active endpoint.
 */
class HubEvents
{
    public const COMPETITOR_SET_CHANGED = 'competitorset.changed';

    public const ORG_TOOL_LINKED = 'org.tool_linked';

    public function record(string $event, string $orgId, string $entityId, array $tools): void
    {
        $tools = WebhookEndpoint::where('active', true)->whereIn('tool', array_unique($tools))->pluck('tool');

        foreach ($tools as $tool) {
            WebhookOutboxEntry::create([
                'tool' => $tool,
                'event' => $event,
                'org_id' => $orgId,
                'entity_id' => $entityId,
                'occurred_at' => now(),
                'next_attempt_at' => now(),
            ]);
        }
    }
}
