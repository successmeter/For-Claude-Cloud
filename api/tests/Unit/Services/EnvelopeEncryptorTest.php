<?php
// api/tests/Unit/Services/EnvelopeEncryptorTest.php
namespace Tests\Unit\Services;

use App\Services\Encryption\EnvelopeEncryptor;
use App\Services\Encryption\LocalFileKmsDriver;
use Illuminate\Support\Facades\Storage;
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
        // IMPORTANT finding #6 (final whole-branch review): this test previously only
        // proved decrypt() threw after destroyOrgKey() -- which was ALSO true of the
        // pre-fix bug (decrypt() called getOrCreateDataKey(), so it transparently
        // minted a brand-new, unrelated key and then failed to decrypt against it,
        // the wrong-key case already covered by test_different_orgs_get_different_keys).
        // That didn't distinguish "key is genuinely gone" from "key exists but is
        // wrong" -- and silently left a stray new key file on disk for an org whose
        // key was supposed to be destroyed. This asserts both: the key file is
        // actually absent, decrypt() throws specifically because no key exists (not
        // merely because decryption failed), and no new key file gets created as a
        // side effect of the failed decrypt attempt.
        $kms = new LocalFileKmsDriver();
        $encryptor = new EnvelopeEncryptor($kms);
        $orgId = (string) \Illuminate\Support\Str::uuid();
        $blob = $encryptor->encrypt($orgId, 'secret');

        $disk = Storage::disk('local');
        $keyPath = "kms-keys/{$orgId}.key";
        $this->assertTrue($disk->exists($keyPath));

        $encryptor->destroyOrgKey($orgId);

        $this->assertFalse($disk->exists($keyPath));

        try {
            $encryptor->decrypt($orgId, $blob);
            $this->fail('Expected a RuntimeException to be thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No data key exists', $e->getMessage());
        }

        // The failed decrypt must not have resurrected a key for this org.
        $this->assertFalse($disk->exists($keyPath));
    }
}
