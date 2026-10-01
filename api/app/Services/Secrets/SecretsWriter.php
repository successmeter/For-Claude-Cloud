<?php
// api/app/Services/Secrets/SecretsWriter.php
namespace App\Services\Secrets;

/**
 * Writes values into a JSON secret held elsewhere (AWS Secrets Manager in production), so a
 * command can hand another service its credentials without printing them.
 */
interface SecretsWriter
{
    /** Adds or replaces these keys in the secret's JSON; other keys are kept. */
    public function merge(string $secretId, array $values): void;
}
