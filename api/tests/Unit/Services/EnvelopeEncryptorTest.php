<?php
// api/tests/Unit/Services/EnvelopeEncryptorTest.php
namespace Tests\Unit\Services;

use App\Services\Encryption\EnvelopeEncryptor;
use App\Services\Encryption\LocalFileKmsDriver;
use Tests\TestCase;

class EnvelopeEncryptorTest extends TestCase
{
    public function test_round_trip_encrypts_and_decrypts(): void
    {
        $encryptor = new EnvelopeEncryptor(new LocalFileKmsDriver());
        $orgId = (string) \Illuminate\Support\Str::uuid();

        $blob = $encryptor->encrypt($orgId, 'super-secret-pos-token');

        $this->assertNotEquals('super-secret-pos-token', $blob);
        $this->assertEquals('super-secret-pos-token', $encryptor->decrypt($orgId, $blob));
    }

    public function test_different_orgs_get_different_keys(): void
    {
        $encryptor = new EnvelopeEncryptor(new LocalFileKmsDriver());
        $orgA = (string) \Illuminate\Support\Str::uuid();
        $orgB = (string) \Illuminate\Support\Str::uuid();

        $blob = $encryptor->encrypt($orgA, 'secret');

        $this->expectException(\RuntimeException::class);
        $encryptor->decrypt($orgB, $blob); // wrong org's key must not decrypt
    }

    public function test_destroying_an_org_key_makes_ciphertext_permanently_unreadable(): void
    {
        $encryptor = new EnvelopeEncryptor(new LocalFileKmsDriver());
        $orgId = (string) \Illuminate\Support\Str::uuid();
        $blob = $encryptor->encrypt($orgId, 'secret');

        $encryptor->destroyOrgKey($orgId);

        $this->expectException(\RuntimeException::class);
        $encryptor->decrypt($orgId, $blob);
    }
}
