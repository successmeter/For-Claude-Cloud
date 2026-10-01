<?php
// api/app/Console/Commands/HubClient.php
namespace App\Console\Commands;

use App\Hub\Identity\FirstPartyClient;
use App\Services\Secrets\SecretsWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Registers a tool's OAuth client: confidential, authorization code + refresh token for signing
 * users in, client credentials for the tool's own calls. The secret is printed once (Passport
 * stores only its hash), or with --aws-secret written straight into the tool's secret in AWS
 * Secrets Manager (HUB_CLIENT_ID, HUB_CLIENT_SECRET) and never shown.
 */
class HubClient extends Command
{
    protected $signature = 'hub:client {tool : web} {redirect : exact OAuth redirect URI}
        {post_logout_redirect : where /oauth/logout may send the browser back to}
        {--aws-secret= : write the id and secret into this Secrets Manager secret instead of printing them}';

    protected $description = 'Register a tool as an OAuth/OIDC client of the Hub';

    public function handle(SecretsWriter $secrets): int
    {
        $tool = $this->argument('tool');
        $uris = [$this->argument('redirect'), $this->argument('post_logout_redirect')];

        if ($tool !== 'web') {
            $this->error('Unknown tool. Supported: web');

            return self::FAILURE;
        }
        foreach ($uris as $uri) {
            if (! filter_var($uri, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#', $uri)) {
                $this->error("Not an http(s) URL: {$uri}");

                return self::FAILURE;
            }
        }

        $secret = Str::random(48);
        $client = FirstPartyClient::forceCreate([
            'name' => $tool,
            'secret' => $secret,
            'provider' => 'users',
            'redirect_uris' => [$uris[0]],
            'post_logout_redirect_uris' => [$uris[1]],
            'grant_types' => ['authorization_code', 'refresh_token', 'client_credentials'],
            'revoked' => false,
            'hub_tool' => $tool,
        ]);

        if ($secretId = $this->option('aws-secret')) {
            $secrets->merge($secretId, ['HUB_CLIENT_ID' => (string) $client->id, 'HUB_CLIENT_SECRET' => $secret]);
            $this->info("Client for {$tool} registered; HUB_CLIENT_ID and HUB_CLIENT_SECRET written to {$secretId}.");

            return self::SUCCESS;
        }

        $this->info("Client for {$tool} registered.");
        $this->line("HUB_CLIENT_ID={$client->id}");
        $this->line("HUB_CLIENT_SECRET={$secret}   (shown once)");

        return self::SUCCESS;
    }
}
