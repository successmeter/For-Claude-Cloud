<?php
// api/tests/Feature/Hub/HandOffSecretsTest.php

namespace Tests\Feature\Hub;

use App\Hub\Identity\FirstPartyClient;
use App\Hub\Models\WebhookEndpoint;
use App\Services\Secrets\AwsSecretsWriter;
use App\Services\Secrets\SecretsWriter;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\SecretsManager\SecretsManagerClient;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\TestCase;

/**
 * Registering the Web tool in AWS: hub:client and hub:webhook-endpoint write the credentials into
 * the tool's secret (--aws-secret) instead of printing them, so they never reach the task logs.
 */
class HandOffSecretsTest extends TestCase
{
    use RefreshesPrivilegedDatabase;

    /** @var array<string, array> secret id => values written */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(SecretsWriter::class, new class($this->written) implements SecretsWriter
        {
            public function __construct(private array &$written) {}

            public function merge(string $secretId, array $values): void
            {
                $this->written[$secretId] = array_merge($this->written[$secretId] ?? [], $values);
            }
        });
    }

    public function test_hub_client_writes_the_credentials_instead_of_printing_them(): void
    {
        $this->assertSame(0, Artisan::call('hub:client', [
            'tool' => 'web', 'redirect' => 'https://web.example.com/auth/callback', 'post_logout_redirect' => 'https://web.example.com/',
            '--aws-secret' => 'success-meter-web/app',
        ]));
        $output = Artisan::output();

        $values = $this->written['success-meter-web/app'];
        $client = FirstPartyClient::findOrFail($values['HUB_CLIENT_ID']);
        $this->assertSame('web', $client->hub_tool);
        $this->assertStringNotContainsString($values['HUB_CLIENT_SECRET'], $output);
        $this->assertStringContainsString('written to success-meter-web/app', $output);

        $this->post('/oauth/token', [
            'grant_type' => 'client_credentials', 'client_id' => $client->id, 'client_secret' => $values['HUB_CLIENT_SECRET'],
        ])->assertOk();
    }

    public function test_webhook_endpoint_writes_the_signing_secret_instead_of_printing_it(): void
    {
        $this->assertSame(0, Artisan::call('hub:webhook-endpoint', [
            'tool' => 'web', 'url' => 'https://web-api.example.com/hooks/hub', '--aws-secret' => 'success-meter-web/app',
        ]));
        $output = Artisan::output();

        $secret = $this->written['success-meter-web/app']['HUB_WEBHOOK_SECRET'];
        $this->assertSame($secret, WebhookEndpoint::where('tool', 'web')->where('active', true)->sole()->secret);
        $this->assertStringNotContainsString($secret, $output);
    }

    public function test_without_the_option_the_commands_still_print(): void
    {
        Artisan::call('hub:webhook-endpoint', ['tool' => 'web', 'url' => 'https://web-api.example.com/hooks/hub']);

        $this->assertStringContainsString('Secret (shown once):', Artisan::output());
        $this->assertSame([], $this->written);
    }

    public function test_the_aws_writer_keeps_the_other_keys(): void
    {
        $mock = new MockHandler;
        $sent = [];
        $client = new SecretsManagerClient(['region' => 'ap-southeast-2', 'version' => 'latest', 'credentials' => false,
            'handler' => function (CommandInterface $cmd, $request) use ($mock, &$sent) {
                $sent[] = [$cmd->getName(), $cmd->toArray()];

                return $mock($cmd, $request);
            }]);
        $mock->append(new Result(['SecretString' => json_encode(['SESSION_SECRET' => 's', 'HUB_CLIENT_ID' => 'old'])]));
        $mock->append(new Result([]));

        (new AwsSecretsWriter($client))->merge('success-meter-web/app', ['HUB_CLIENT_ID' => 'new', 'HUB_CLIENT_SECRET' => 'x']);

        $this->assertSame(['GetSecretValue', 'PutSecretValue'], array_column($sent, 0));
        $this->assertSame('success-meter-web/app', $sent[1][1]['SecretId']);
        $this->assertSame(['SESSION_SECRET' => 's', 'HUB_CLIENT_ID' => 'new', 'HUB_CLIENT_SECRET' => 'x'], json_decode($sent[1][1]['SecretString'], true));
    }

    public function test_the_aws_writer_refuses_a_secret_that_is_not_json(): void
    {
        $mock = new MockHandler([new Result(['SecretString' => 'plain text'])]);
        $client = new SecretsManagerClient(['region' => 'ap-southeast-2', 'version' => 'latest', 'credentials' => false, 'handler' => $mock]);

        $this->expectException(\RuntimeException::class);
        (new AwsSecretsWriter($client))->merge('x', ['a' => 'b']);
    }
}
