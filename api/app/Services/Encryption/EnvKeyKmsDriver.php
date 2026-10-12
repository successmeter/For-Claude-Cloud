<?php

// api/app/Services/Encryption/EnvKeyKmsDriver.php

namespace App\Services\Encryption;

use Illuminate\Support\Facades\DB;

/**
 * Trial driver for hosts without AWS (Laravel Cloud): each org's data key is wrapped by one master key from
 * KMS_ENV_KEY (base64, 32 bytes) with XChaCha20-Poly1305, the org id as associated data (as KMS uses it as
 * encryption context), and stored in org_data_keys like AwsKmsDriver's. The master key lives in the host's
 * environment, so anyone who can read that can read the data keys: at launch AwsKmsDriver re-wraps each key
 * with KMS (hub:kms-move-to-aws) and KMS_ENV_KEY is deleted. Only the data keys move; no data is re-encrypted.
 */
class EnvKeyKmsDriver implements KeyManagementService
{
    public const MARKER = 'env:';

    private string $masterKey;

    /** @var array<string, string> */
    private array $plaintext = [];

    public function __construct(string $base64Key)
    {
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new \RuntimeException('KMS_ENV_KEY must be 32 random bytes, base64-encoded: openssl rand -base64 32');
        }
        $this->masterKey = $key;
    }

    public static function wraps(string $kmsKeyId): bool
    {
        return str_starts_with($kmsKeyId, self::MARKER);
    }

    public function getOrCreateDataKey(string $orgId): string
    {
        return $this->plaintext[$orgId] ??= $this->unwrap($orgId) ?? $this->create($orgId);
    }

    public function getDataKey(string $orgId): string
    {
        return $this->plaintext[$orgId] ??= $this->unwrap($orgId)
            ?? throw new \RuntimeException("No data key exists for org {$orgId}");
    }

    public function destroyDataKey(string $orgId): void
    {
        DB::table('org_data_keys')->where('org_id', $orgId)->update(['wrapped_key' => null, 'destroyed_at' => now()]);
        unset($this->plaintext[$orgId]);
    }

    /** Opens a stored env-wrapped key (AwsKmsDriver uses this to move it to KMS). */
    public function open(string $orgId, string $kmsKeyId, string $wrappedKey): string
    {
        if ($kmsKeyId !== $this->marker()) {
            throw new \RuntimeException("The data key for org {$orgId} was wrapped with a different KMS_ENV_KEY");
        }
        $raw = base64_decode($wrappedKey);
        $nonce = substr($raw, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $key = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($raw, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES), $this->context($orgId), $nonce, $this->masterKey);
        if ($key === false) {
            throw new \RuntimeException("Unable to unwrap the data key for org {$orgId}");
        }

        return $key;
    }

    private function unwrap(string $orgId): ?string
    {
        $row = DB::table('org_data_keys')->where('org_id', $orgId)->first();
        if ($row === null) {
            return null;
        }
        if ($row->wrapped_key === null) {
            throw new \RuntimeException("The data key for org {$orgId} was destroyed");
        }
        if (! self::wraps($row->kms_key_id)) {
            throw new \RuntimeException("The data key for org {$orgId} is held by AWS KMS: set KMS_DRIVER=aws");
        }

        return $this->open($orgId, $row->kms_key_id, $row->wrapped_key);
    }

    private function create(string $orgId): string
    {
        $dataKey = random_bytes(32);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $wrapped = $nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($dataKey, $this->context($orgId), $nonce, $this->masterKey);
        $inserted = DB::table('org_data_keys')->insertOrIgnore([
            'org_id' => $orgId,
            'wrapped_key' => base64_encode($wrapped),
            'kms_key_id' => $this->marker(),
            'created_at' => now(),
        ]);

        // Another process created it first: use theirs, so every writer shares one key.
        return $inserted === 1 ? $dataKey : $this->unwrap($orgId);
    }

    private function context(string $orgId): string
    {
        return "org_id={$orgId}";
    }

    /** Names the master key without revealing it, so a changed KMS_ENV_KEY is reported as such. */
    private function marker(): string
    {
        return self::MARKER.substr(hash_hmac('sha256', 'org-data-key-wrap', $this->masterKey), 0, 16);
    }
}
