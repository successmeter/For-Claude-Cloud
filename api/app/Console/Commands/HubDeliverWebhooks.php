<?php
// api/app/Console/Commands/HubDeliverWebhooks.php
namespace App\Console\Commands;

use App\Hub\Jobs\DeliverWebhook;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Scheduled every minute: delivers every due outbox entry, so an event whose after-commit dispatch
 * was lost (worker restart, queue outage) or that is waiting for a retry still goes out.
 */
class HubDeliverWebhooks extends Command
{
    protected $signature = 'hub:deliver-webhooks {--limit=200}';

    protected $description = 'Deliver due Hub webhook events';

    public function handle(): int
    {
        $ids = DB::table('webhook_outbox')
            ->whereNull('delivered_at')
            ->whereNull('failed_at')
            ->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')
            ->limit((int) $this->option('limit'))
            ->pluck('id');

        foreach ($ids as $id) {
            DeliverWebhook::dispatch($id);
        }

        $this->info("Queued {$ids->count()} webhook deliveries.");

        return self::SUCCESS;
    }
}
