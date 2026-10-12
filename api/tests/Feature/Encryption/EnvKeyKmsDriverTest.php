<?php

// api/tests/Feature/Encryption/EnvKeyKmsDriverTest.php

namespace Tests\Feature\Encryption;

use App\Services\Encryption\AwsKmsDriver;
use App\Services\Encryption\EnvelopeEncryptor;
use App\Services\Encryption\EnvKeyKmsDriver;
use App\Services\Encryption\KeyManagementService;
use App\Services\Tenancy\TenantContext;
use Aws\CommandInterface;
use Aws\Kms\KmsClient;
use Aws\MockHandler;
use Aws\Result;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\SpaRequests;
use Tests\TestCase;

/**
 * The trial's key option (Laravel Cloud, no AWS): org data keys wrapped by one master key from the environment,
 * stored like the AWS driver's, and re-wrapped by AWS KMS at launch without touching any encrypted data.
 */
class EnvKeyKmsDriverTest extends TestCase
{
    use RefreshesPrivilegedDatabase, SpaRequests;

    private const KEY_ARN = 'arn:aws:kms:ap-southeast-2:111122223333:key/test';

    /** @var list<CommandInterface> */
    private array $calls = [];

    private function envKey(): string
    {
        return base64_encode(str_repeat("\x07", 32));
    }

    private function aws(MockHandler $mock, ?EnvKeyKmsDriver $previous): AwsKmsDriver
    {
        $client = new KmsClient(['region' => 'ap-southeast-2', 'version' => 'latest', 'credentials' => false,
            'handler' => function (CommandInterface $cmd, $request) use ($mock) {
                $this->calls[] = $cmd;

                return $mock($cmd, $request);
            }]);

        return new AwsKmsDriver($client, self::KEY_ARN, $previous);
    }

    public function test_keys_are_wrapped_by_the_env_key_bound_to_the_org_and_survive_a_new_process(): void
    {
        $org = $this->org();
        $key = TenantContext::run($org->id, fn () => (new EnvKeyKmsDriver($this->envKey()))->getOrCreateDataKey($org->id));

        $row = TenantContext::run($org->id, fn () => DB::table('org_data_keys')->where('org_id', $org->id)->first());
        $this->assertStringStartsWith('env:', $row->kms_key_id);
        $this->assertStringNotContainsString(base64_encode($key), $row->wrapped_key, 'only the wrapped form is stored');
        $this->assertSame(32, strlen($key));

        $again = TenantContext::run($org->id, fn () => (new EnvKeyKmsDriver($this->envKey()))->getDataKey($org->id));
        $this->assertSame($key, $again, 'a deploy (new process, new disk) still reads the key');

        $blob = TenantContext::run($org->id, fn () => (new EnvelopeEncryptor(new EnvKeyKmsDriver($this->envKey())))->encrypt($org->id, 'sales'));
        $this->assertSame('sales', TenantContext::run($org->id, fn () => (new EnvelopeEncryptor(new EnvKeyKmsDriver($this->envKey())))->decrypt($org->id, $blob)));
    }

    public function test_a_wrapped_key_moved_to_another_org_does_not_open(): void
    {
        $a = $this->org('A');
        $b = $this->org('B');
        $driver = new EnvKeyKmsDriver($this->envKey());
        TenantContext::run($a->id, fn () => $driver->getOrCreateDataKey($a->id));
        $wrapped = TenantContext::run($a->id, fn () => DB::table('org_data_keys')->where('org_id', $a->id)->value('wrapped_key'));
        $marker = TenantContext::run($a->id, fn () => DB::table('org_data_keys')->where('org_id', $a->id)->value('kms_key_id'));
        TenantContext::run($b->id, fn () => DB::table('org_data_keys')->insert(['org_id' => $b->id, 'wrapped_key' => $wrapped, 'kms_key_id' => $marker, 'created_at' => now()]));

        $this->expectException(\RuntimeException::class);
        TenantContext::run($b->id, fn () => (new EnvKeyKmsDriver($this->envKey()))->getDataKey($b->id));
    }

    public function test_a_different_env_key_is_named_as_the_problem(): void
    {
        $org = $this->org();
        TenantContext::run($org->id, fn () => (new EnvKeyKmsDriver($this->envKey()))->getOrCreateDataKey($org->id));

        $this->expectExceptionMessage('KMS_ENV_KEY');
        TenantContext::run($org->id, fn () => (new EnvKeyKmsDriver(base64_encode(random_bytes(32))))->getDataKey($org->id));
    }

    public function test_a_missing_or_short_env_key_refuses_to_start(): void
    {
        foreach (['', base64_encode('short')] as $bad) {
            try {
                new EnvKeyKmsDriver($bad);
                $this->fail('accepted a bad key');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('KMS_ENV_KEY', $e->getMessage());
            }
        }
    }

    public function test_missing_and_destroyed_keys_are_errors_not_new_keys(): void
    {
        $org = $this->org();
        $driver = new EnvKeyKmsDriver($this->envKey());
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
        TenantContext::run($org->id, fn () => (new EnvKeyKmsDriver($this->envKey()))->getDataKey($org->id));
    }

    public function test_at_launch_the_aws_driver_rewraps_an_env_key_once_and_the_data_still_opens(): void
    {
        $org = $this->org();
        $blob = TenantContext::run($org->id, fn () => (new EnvelopeEncryptor(new EnvKeyKmsDriver($this->envKey())))->encrypt($org->id, 'square-token'));
        $plain = TenantContext::run($org->id, fn () => (new EnvKeyKmsDriver($this->envKey()))->getDataKey($org->id));

        $mock = new MockHandler([new Result(['CiphertextBlob' => 'wrapped-by-kms', 'KeyId' => self::KEY_ARN])]);
        $driver = $this->aws($mock, new EnvKeyKmsDriver($this->envKey()));

        $this->assertSame('square-token', TenantContext::run($org->id, fn () => (new EnvelopeEncryptor($driver))->decrypt($org->id, $blob)));
        $this->assertSame('Encrypt', $this->calls[0]->getName());
        $this->assertSame(['KeyId' => self::KEY_ARN, 'Plaintext' => $plain, 'EncryptionContext' => ['org_id' => $org->id]],
            array_intersect_key($this->calls[0]->toArray(), array_flip(['KeyId', 'Plaintext', 'EncryptionContext'])));
        $row = TenantContext::run($org->id, fn () => DB::table('org_data_keys')->where('org_id', $org->id)->first());
        $this->assertSame([self::KEY_ARN, 'wrapped-by-kms'], [$row->kms_key_id, base64_decode($row->wrapped_key)]);

        // From now on it is an ordinary KMS key: a later process decrypts it with KMS, no env key needed.
        $later = new MockHandler([new Result(['Plaintext' => $plain, 'KeyId' => self::KEY_ARN])]);
        $this->assertSame('square-token', TenantContext::run($org->id, fn () => (new EnvelopeEncryptor($this->aws($later, null)))->decrypt($org->id, $blob)));
        $this->assertSame('Decrypt', end($this->calls)->getName());
    }

    public function test_an_env_key_without_the_env_key_set_says_what_to_do(): void
    {
        $org = $this->org();
        TenantContext::run($org->id, fn () => (new EnvKeyKmsDriver($this->envKey()))->getOrCreateDataKey($org->id));

        $this->expectExceptionMessage('KMS_ENV_KEY');
        TenantContext::run($org->id, fn () => $this->aws(new MockHandler, null)->getDataKey($org->id));
    }

    public function test_the_move_command_rewraps_every_org_that_has_a_key(): void
    {
        [$square, $uploads, $none] = [$this->org('Square'), $this->org('Uploads'), $this->org('None')];
        foreach ([$square, $uploads] as $org) {
            TenantContext::run($org->id, fn () => (new EnvKeyKmsDriver($this->envKey()))->getOrCreateDataKey($org->id));
        }
        DB::table('pos_orgs')->insert(['org_id' => $square->id]);
        DB::table('ingest_orgs')->insert(['org_id' => $uploads->id]);
        DB::table('ingest_orgs')->insert(['org_id' => $none->id]);

        $mock = new MockHandler([new Result(['CiphertextBlob' => 'k1', 'KeyId' => self::KEY_ARN]), new Result(['CiphertextBlob' => 'k2', 'KeyId' => self::KEY_ARN])]);
        $this->app->instance(KeyManagementService::class, $this->aws($mock, new EnvKeyKmsDriver($this->envKey())));
        config(['kms.driver' => 'aws']);

        $this->artisan('hub:kms-move-to-aws')->expectsOutputToContain('Moved 2 keys to AWS KMS; 0 left')->assertSuccessful();

        foreach ([$square, $uploads] as $org) {
            $this->assertSame(self::KEY_ARN, TenantContext::run($org->id, fn () => DB::table('org_data_keys')->where('org_id', $org->id)->value('kms_key_id')));
        }
        $this->artisan('hub:kms-move-to-aws')->expectsOutputToContain('Moved 0 keys')->assertSuccessful();
    }

    public function test_the_move_command_refuses_before_the_driver_is_aws(): void
    {
        $this->artisan('hub:kms-move-to-aws')->expectsOutputToContain('KMS_DRIVER=aws')->assertFailed();
    }

    public function test_the_driver_follows_config(): void
    {
        config(['kms.driver' => 'env', 'kms.env_key' => $this->envKey()]);
        $this->app->forgetInstance(KeyManagementService::class);
        $this->assertInstanceOf(EnvKeyKmsDriver::class, app(KeyManagementService::class));

        config(['kms.driver' => 'aws', 'kms.aws_key_id' => self::KEY_ARN, 'kms.aws_region' => 'ap-southeast-2']);
        $this->app->forgetInstance(KeyManagementService::class);
        $aws = app(KeyManagementService::class);
        $this->assertInstanceOf(AwsKmsDriver::class, $aws);
        $this->assertTrue($aws->canReadEnvKeys(), 'with KMS_ENV_KEY still set, the AWS driver can move env keys');
    }
}
