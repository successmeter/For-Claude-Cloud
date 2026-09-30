<?php
// api/tests/Feature/Encryption/AwsKmsDriverTest.php

namespace Tests\Feature\Encryption;

use App\Services\Encryption\AwsKmsDriver;
use App\Services\Encryption\EnvelopeEncryptor;
use App\Services\Encryption\KeyManagementService;
use App\Services\Encryption\LocalFileKmsDriver;
use App\Services\Tenancy\TenantContext;
use Aws\CommandInterface;
use Aws\Kms\KmsClient;
use Aws\MockHandler;
use Aws\Result;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\SpaRequests;
use Tests\TestCase;

class AwsKmsDriverTest extends TestCase
{
    use RefreshesPrivilegedDatabase, SpaRequests;

    private const KEY_ARN = 'arn:aws:kms:ap-southeast-2:111122223333:key/test';

    /** @var list<CommandInterface> */
    private array $calls = [];

    private MockHandler $mock;

    private function driver(): AwsKmsDriver
    {
        $this->mock = new MockHandler;
        $client = new KmsClient(['region' => 'ap-southeast-2', 'version' => 'latest', 'credentials' => false,
            'handler' => function (CommandInterface $cmd, $request) {
                $this->calls[] = $cmd;

                return ($this->mock)($cmd, $request);
            }]);

        return new AwsKmsDriver($client, self::KEY_ARN);
    }

    public function test_a_new_org_gets_a_data_key_wrapped_by_kms_with_the_org_as_context(): void
    {
        $org = $this->org();
        $driver = $this->driver();
        $plain = random_bytes(32);
        $this->mock->append(new Result(['Plaintext' => $plain, 'CiphertextBlob' => 'wrapped-by-kms', 'KeyId' => self::KEY_ARN]));

        $key = TenantContext::run($org->id, fn () => $driver->getOrCreateDataKey($org->id));

        $this->assertSame($plain, $key);
        $this->assertSame('GenerateDataKey', $this->calls[0]->getName());
        $this->assertSame(['KeyId' => self::KEY_ARN, 'KeySpec' => 'AES_256', 'EncryptionContext' => ['org_id' => $org->id]],
            array_intersect_key($this->calls[0]->toArray(), array_flip(['KeyId', 'KeySpec', 'EncryptionContext'])));
        $stored = TenantContext::run($org->id, fn () => DB::table('org_data_keys')->where('org_id', $org->id)->first());
        $this->assertSame('wrapped-by-kms', base64_decode($stored->wrapped_key));
    }

    public function test_an_existing_key_is_unwrapped_once_per_process(): void
    {
        $org = $this->org();
        $plain = random_bytes(32);
        TenantContext::run($org->id, fn () => DB::table('org_data_keys')->insert([
            'org_id' => $org->id, 'wrapped_key' => base64_encode('wrapped'), 'kms_key_id' => self::KEY_ARN, 'created_at' => now(),
        ]));
        $driver = $this->driver();
        $this->mock->append(new Result(['Plaintext' => $plain, 'KeyId' => self::KEY_ARN]));

        $first = TenantContext::run($org->id, fn () => $driver->getDataKey($org->id));
        $second = TenantContext::run($org->id, fn () => $driver->getOrCreateDataKey($org->id));

        $this->assertSame([$plain, $plain], [$first, $second]);
        $this->assertCount(1, $this->calls);
        $this->assertSame('Decrypt', $this->calls[0]->getName());
        $this->assertSame(['org_id' => $org->id], $this->calls[0]->toArray()['EncryptionContext']);
        $this->assertSame('wrapped', $this->calls[0]->toArray()['CiphertextBlob']);
    }

    public function test_a_missing_or_destroyed_key_is_an_error_not_a_new_key(): void
    {
        $org = $this->org();
        $driver = $this->driver();
        $this->mock->append(new Result(['Plaintext' => random_bytes(32), 'CiphertextBlob' => 'w', 'KeyId' => self::KEY_ARN]));

        try {
            TenantContext::run($org->id, fn () => $driver->getDataKey($org->id));
            $this->fail('getDataKey minted a key');
        } catch (\RuntimeException) {
        }

        TenantContext::run($org->id, function () use ($driver, $org) {
            $driver->getOrCreateDataKey($org->id);
            $driver->destroyDataKey($org->id);
        });

        $this->expectException(\RuntimeException::class);
        TenantContext::run($org->id, fn () => $this->driver()->getDataKey($org->id));
    }

    public function test_keys_are_invisible_to_other_orgs(): void
    {
        $a = $this->org('A');
        $b = $this->org('B');
        $driver = $this->driver();
        $this->mock->append(new Result(['Plaintext' => random_bytes(32), 'CiphertextBlob' => 'w', 'KeyId' => self::KEY_ARN]));
        TenantContext::run($a->id, fn () => $driver->getOrCreateDataKey($a->id));

        $this->assertSame(0, TenantContext::run($b->id, fn () => DB::table('org_data_keys')->count()));
    }

    public function test_envelope_encryption_round_trips_through_the_aws_driver(): void
    {
        $org = $this->org();
        $driver = $this->driver();
        $plain = random_bytes(32);
        $this->mock->append(new Result(['Plaintext' => $plain, 'CiphertextBlob' => 'w', 'KeyId' => self::KEY_ARN]));

        $blob = TenantContext::run($org->id, fn () => (new EnvelopeEncryptor($driver))->encrypt($org->id, 'sales'));

        $this->assertSame('sales', TenantContext::run($org->id, fn () => (new EnvelopeEncryptor($driver))->decrypt($org->id, $blob)));
    }

    public function test_the_driver_follows_config(): void
    {
        $this->assertInstanceOf(LocalFileKmsDriver::class, app(KeyManagementService::class));

        config(['kms.driver' => 'aws', 'kms.aws_key_id' => self::KEY_ARN, 'kms.aws_region' => 'ap-southeast-2']);
        $this->app->forgetInstance(KeyManagementService::class);

        $this->assertInstanceOf(AwsKmsDriver::class, app(KeyManagementService::class));
    }
}
