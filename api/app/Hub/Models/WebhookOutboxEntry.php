<?php
// api/app/Hub/Models/WebhookOutboxEntry.php
namespace App\Hub\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One webhook to deliver to one tool. The row id is the event id consumers dedupe on. */
class WebhookOutboxEntry extends Model
{
    use HasUuids;

    protected $table = 'webhook_outbox';

    public $timestamps = false;

    protected $fillable = ['tool', 'event', 'org_id', 'entity_id', 'occurred_at', 'next_attempt_at'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /** The webhook body (schema: webhook-event). */
    public function payload(): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'org_id' => $this->org_id,
            'entity_id' => $this->entity_id,
            'occurred_at' => $this->occurred_at->toIso8601ZuluString(),
            'contract' => 'v1',
        ];
    }
}
