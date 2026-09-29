<?php
// api/app/Hub/Webhooks/WebhookSigner.php
namespace App\Hub\Webhooks;

use App\Hub\Models\WebhookEndpoint;

/**
 * Hub-Signature: v1=<hex HMAC-SHA256(secret, timestamp + "." + body)>. While a secret is being
 * rotated, a second v1= value signed with the previous secret follows, so the consumer can switch
 * secrets without dropping events (contract/v1/README.md).
 */
class WebhookSigner
{
    public function headers(WebhookEndpoint $endpoint, string $eventId, string $body, int $timestamp): array
    {
        $signatures = [$this->sign($endpoint->secret, $timestamp, $body)];
        if ($endpoint->previous_secret) {
            $signatures[] = $this->sign($endpoint->previous_secret, $timestamp, $body);
        }

        return [
            'Hub-Event-Id' => $eventId,
            'Hub-Timestamp' => (string) $timestamp,
            'Hub-Signature' => implode(', ', $signatures),
        ];
    }

    private function sign(string $secret, int $timestamp, string $body): string
    {
        return 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
