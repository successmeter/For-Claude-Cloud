<?php
// api/app/Services/Encryption/EnvelopeEncryptor.php
namespace App\Services\Encryption;

class EnvelopeEncryptor
{
    public function __construct(private KeyManagementService $kms) {}

    public function encrypt(string $orgId, string $plaintext): string
    {
        $key = $this->kms->getOrCreateDataKey($orgId);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);

        return base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $orgId, string $blob): string
    {
        // IMPORTANT finding #6 (final whole-branch review): must use getDataKey(),
        // not getOrCreateDataKey() -- decrypting must never silently mint a brand-new
        // data key for an org whose key was deliberately destroyed (destroyOrgKey()),
        // which would mask the fact that the org's key is genuinely, permanently
        // gone. getDataKey() throws instead.
        $key = $this->kms->getDataKey($orgId);
        $raw = base64_decode($blob);
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed: wrong key or corrupted ciphertext');
        }
        return $plaintext;
    }

    public function destroyOrgKey(string $orgId): void
    {
        $this->kms->destroyDataKey($orgId);
    }
}
