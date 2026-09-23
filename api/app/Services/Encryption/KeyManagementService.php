<?php
// api/app/Services/Encryption/KeyManagementService.php
namespace App\Services\Encryption;

interface KeyManagementService
{
    /** Returns the org's raw 32-byte data key, generating and persisting one if it doesn't exist. */
    public function getOrCreateDataKey(string $orgId): string;

    /** Permanently destroys the org's data key. Any ciphertext under it becomes unreadable forever. */
    public function destroyDataKey(string $orgId): void;
}
