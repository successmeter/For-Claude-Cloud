<?php
// api/app/Services/Encryption/LocalFileKmsDriver.php
namespace App\Services\Encryption;

use Illuminate\Support\Facades\Storage;

/**
 * Dev/test-only KMS driver. Wraps each org's data key with a single master key
 * read from config('kms.master_key') and stores the wrapped key on the local disk.
 * NOT for production use — see the plan's Open Items for the AWS KMS driver this
 * interface exists to make swappable.
 */
class LocalFileKmsDriver implements KeyManagementService
{
    /**
     * MINOR finding #15 (final whole-branch review): this driver reads its master
     * key from local disk-backed config with no access control beyond the
     * filesystem, and wraps org data keys with it via a single static secret -- fine
     * for local dev/testing, unacceptable for production (see the plan's Open Items
     * for the real KMS driver, e.g. AWS KMS, this interface exists to make
     * swappable). Failing fast here means this can't silently become the production
     * KMS just because AppServiceProvider's binding was never swapped before a
     * production deploy.
     */
    public function __construct()
    {
        if (! app()->environment('local', 'testing')) {
            throw new \RuntimeException(
                'LocalFileKmsDriver must never be used outside local/testing environments.'
            );
        }
    }

    private function masterKey(): string
    {
        $key = config('kms.master_key');
        if (! $key) {
            throw new \RuntimeException('kms.master_key is not configured');
        }
        return sodium_hex2bin($key);
    }

    private function path(string $orgId): string
    {
        return "kms-keys/{$orgId}.key";
    }

    public function getOrCreateDataKey(string $orgId): string
    {
        $disk = Storage::disk('local');
        $path = $this->path($orgId);

        if ($disk->exists($path)) {
            $wrapped = base64_decode($disk->get($path));
            $nonce = substr($wrapped, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = substr($wrapped, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $key = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->masterKey());
            if ($key === false) {
                throw new \RuntimeException("Unable to unwrap data key for org {$orgId}");
            }
            return $key;
        }

        $dataKey = sodium_crypto_secretbox_keygen();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $wrapped = $nonce . sodium_crypto_secretbox($dataKey, $nonce, $this->masterKey());
        $disk->put($path, base64_encode($wrapped));

        return $dataKey;
    }

    public function getDataKey(string $orgId): string
    {
        $disk = Storage::disk('local');
        $path = $this->path($orgId);

        if (! $disk->exists($path)) {
            throw new \RuntimeException("No data key exists for org {$orgId}");
        }

        $wrapped = base64_decode($disk->get($path));
        $nonce = substr($wrapped, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($wrapped, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->masterKey());
        if ($key === false) {
            throw new \RuntimeException("Unable to unwrap data key for org {$orgId}");
        }

        return $key;
    }

    public function destroyDataKey(string $orgId): void
    {
        Storage::disk('local')->delete($this->path($orgId));
    }
}
