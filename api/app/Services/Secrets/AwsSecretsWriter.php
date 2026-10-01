<?php
// api/app/Services/Secrets/AwsSecretsWriter.php
namespace App\Services\Secrets;

use Aws\SecretsManager\SecretsManagerClient;

class AwsSecretsWriter implements SecretsWriter
{
    public function __construct(private SecretsManagerClient $client) {}

    public function merge(string $secretId, array $values): void
    {
        $current = json_decode((string) $this->client->getSecretValue(['SecretId' => $secretId])['SecretString'], true);
        if (! is_array($current)) {
            throw new \RuntimeException("Secret {$secretId} does not hold a JSON object.");
        }

        $this->client->putSecretValue([
            'SecretId' => $secretId,
            'SecretString' => json_encode(array_merge($current, $values), JSON_THROW_ON_ERROR),
        ]);
    }
}
