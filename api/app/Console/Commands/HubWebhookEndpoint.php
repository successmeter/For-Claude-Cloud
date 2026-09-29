<?php
// api/app/Console/Commands/HubWebhookEndpoint.php
namespace App\Console\Commands;

use App\Hub\Models\WebhookEndpoint;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Registers (or rotates the secret of) the webhook endpoint for a tool. The secret is printed once;
 * give it to the tool as its HUB_WEBHOOK_SECRET.
 */
class HubWebhookEndpoint extends Command
{
    protected $signature = 'hub:webhook-endpoint {tool : web} {url : where the Hub POSTs events}
        {--rotate : keep the current secret as previous_secret (both signatures are sent) and issue a new one}';

    protected $description = 'Register a tool\'s webhook endpoint, or rotate its signing secret';

    public function handle(): int
    {
        $tool = $this->argument('tool');
        $url = $this->argument('url');
        if ($tool !== 'web' || ! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#', $url)) {
            $this->error('Usage: hub:webhook-endpoint web https://...');

            return self::FAILURE;
        }

        $secret = bin2hex(random_bytes(32));

        DB::transaction(function () use ($tool, $url, $secret) {
            $current = WebhookEndpoint::where('tool', $tool)->where('active', true)->first();

            if ($current && $this->option('rotate')) {
                $current->fill(['url' => $url, 'previous_secret' => $current->secret, 'secret' => $secret])->save();

                return;
            }

            $current?->fill(['active' => false])->save();
            WebhookEndpoint::create(['tool' => $tool, 'url' => $url, 'secret' => $secret]);
        });

        $this->info("Endpoint for {$tool}: {$url}");
        $this->line("Secret (shown once): {$secret}");

        return self::SUCCESS;
    }
}
