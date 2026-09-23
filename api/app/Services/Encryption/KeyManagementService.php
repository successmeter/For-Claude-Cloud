<?php
// api/app/Services/Encryption/KeyManagementService.php
namespace App\Services\Encryption;

interface KeyManagementService
{
    /** Returns the org's raw 32-byte data key, generating and persisting one if it doesn't exist. */
    public function getOrCreateDataKey(string $orgId): string;

    /**
     * Returns the org's existing raw 32-byte data key. Unlike getOrCreateDataKey(),
     * this never creates one -- IMPORTANT finding #6 (final whole-branch review):
     * EnvelopeEncryptor::decrypt() previously called getOrCreateDataKey() too, which
     * meant decrypting against a deliberately-destroyed (e.g. via destroyDataKey(),
     * an org offboarding/right-to-erasure flow) org silently minted a brand-new,
     * unrelated data key instead of failing -- the ciphertext still failed to decrypt
     * (wrong key), but a stray new key file was left behind, and the failure mode
     * gave no signal that the key was gone versus just wrong. decrypt() must be able
     * to distinguish "no key exists" from "key exists but is wrong" without ever
     * creating a key as a side effect of a read.
     *
     * @throws \RuntimeException if no data key exists for $orgId.
     */
    public function getDataKey(string $orgId): string;

    /** Permanently destroys the org's data key. Any ciphertext under it becomes unreadable forever. */
    public function destroyDataKey(string $orgId): void;
}
