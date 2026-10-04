<?php
// api/tests/Feature/Hosting/LaravelCloudConfigTest.php

namespace Tests\Feature\Hosting;

use App\Ingest\Scanning\OffUploadScanner;
use App\Ingest\Scanning\UploadScanner;
use App\Support\AwsClients;
use App\Support\RuntimeDatabaseGuard;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\TestCase;

/**
 * Hosting on Laravel Cloud (trial phase): the platform injects its own DB_*, AWS_* (object storage)
 * and AWS_DEFAULT_REGION=auto, none of which may reach the restricted runtime connection or KMS.
 */
class LaravelCloudConfigTest extends TestCase
{
    use RefreshesPrivilegedDatabase;

    /** @var array<string, array{0: mixed, 1: mixed, 2: string|false}> $_SERVER, $_ENV and getenv() before the test */
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => [$server, $env, $process]) {
            $server === null ? $this->forget($_SERVER, $key) : $_SERVER[$key] = $server;
            $env === null ? $this->forget($_ENV, $key) : $_ENV[$key] = $env;
            putenv($process === false ? $key : "{$key}={$process}");
        }
        parent::tearDown();
    }

    private function forget(array &$vars, string $key): void
    {
        unset($vars[$key]);
    }

    /** Re-reads a config file as the app would with these environment variables (null: unset). */
    private function configWith(string $file, array $env): array
    {
        foreach ($env as $key => $value) {
            $this->saved[$key] ??= [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];
            if ($value === null) {
                unset($_SERVER[$key], $_ENV[$key]);
                putenv($key);
            } else {
                $_SERVER[$key] = $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }

        return require config_path("{$file}.php");
    }

    public function test_the_runtime_connection_never_takes_the_platforms_owner_url(): void
    {
        $config = $this->configWith('database', [
            'DB_CONNECTION' => 'pgsql', 'HUB_DB_CONNECTION' => 'pgsql_app',
            'DB_URL' => 'postgresql://owner:ownerpw@db.example/main', 'DB_USERNAME' => 'owner', 'DB_APP_USERNAME' => 'app_user',
        ]);

        $this->assertSame('pgsql_app', $config['default'], 'HUB_DB_CONNECTION wins over the injected DB_CONNECTION');
        $this->assertNull($config['connections']['pgsql_app']['url']);
        $this->assertSame('app_user', $config['connections']['pgsql_app']['username']);
        $this->assertSame('postgresql://owner:ownerpw@db.example/main', $config['connections']['pgsql']['url']);
    }

    public function test_the_guard_refuses_a_runtime_that_could_bypass_rls(): void
    {
        $ok = ['default' => 'pgsql_app', 'connections' => [
            'pgsql' => ['username' => 'owner', 'url' => null],
            'pgsql_app' => ['username' => 'app_user', 'url' => null],
        ]];
        $this->assertNull(RuntimeDatabaseGuard::problem($ok));

        $this->assertStringContainsString('HUB_DB_CONNECTION', RuntimeDatabaseGuard::problem(['default' => 'pgsql'] + $ok));
        $owner = $ok;
        $owner['connections']['pgsql_app']['username'] = 'owner';
        $this->assertStringContainsString('DB_APP_USERNAME', RuntimeDatabaseGuard::problem($owner));
        $notApp = $ok;
        $notApp['connections']['pgsql_app']['username'] = 'someone';
        $this->assertStringContainsString('app_user', RuntimeDatabaseGuard::problem($notApp));
    }

    public function test_the_guard_waits_until_the_host_sets_the_environment(): void
    {
        $this->assertFalse($this->configWith('app', ['APP_ENV' => null])['configured'], 'building: nothing is configured yet');
        $this->assertTrue($this->configWith('app', ['APP_ENV' => 'production'])['configured']);
    }

    public function test_kms_uses_its_own_credentials_and_a_real_region(): void
    {
        $config = $this->configWith('kms', [
            'AWS_DEFAULT_REGION' => 'auto', 'KMS_REGION' => '', 'KMS_AWS_ACCESS_KEY_ID' => 'AKIAKMS', 'KMS_AWS_SECRET_ACCESS_KEY' => 'kms-secret',
        ]);
        $this->assertSame('ap-southeast-2', $config['aws_region'], "object storage's 'auto' region is not a KMS region");
        config(['kms' => $config]);

        $credentials = AwsClients::kms()->getCredentials()->wait();
        $this->assertSame(['AKIAKMS', 'kms-secret'], [$credentials->getAccessKeyId(), $credentials->getSecretKey()]);
        $this->assertSame('ap-southeast-2', AwsClients::kms()->getRegion());
    }

    public function test_snapshots_can_live_in_s3_compatible_object_storage(): void
    {
        $disk = $this->configWith('filesystems', [
            'SNAPSHOTS_DRIVER' => 's3', 'SNAPSHOTS_SSE' => 'none', 'AWS_BUCKET' => 'hub-files', 'AWS_ENDPOINT' => 'https://r2.example',
            'AWS_USE_PATH_STYLE_ENDPOINT' => 'true', 'AWS_DEFAULT_REGION' => 'auto',
        ])['disks']['snapshots'];

        $this->assertSame(['s3', 'hub-files', 'https://r2.example', true], [$disk['driver'], $disk['bucket'], $disk['endpoint'], $disk['use_path_style_endpoint']]);
        $this->assertArrayNotHasKey('ServerSideEncryption', $disk['options'], 'snapshots are envelope-encrypted by the app already');

        $aws = $this->configWith('filesystems', ['SNAPSHOTS_DRIVER' => 's3', 'SNAPSHOTS_SSE' => '', 'SNAPSHOTS_BUCKET' => 'hub-snapshots', 'AWS_ENDPOINT' => ''])['disks']['snapshots'];
        $this->assertSame('aws:kms', $aws['options']['ServerSideEncryption'], 'on AWS, KMS encryption at rest stays the default');
        $this->assertSame('hub-snapshots', $aws['bucket']);
    }

    public function test_scanning_can_be_turned_off_explicitly_and_says_so(): void
    {
        config(['ingest.scanner' => 'off']);
        $this->app->detectEnvironment(fn () => 'production');
        Log::spy();

        $scanner = app(UploadScanner::class);
        $this->assertInstanceOf(OffUploadScanner::class, $scanner);
        $this->assertSame('clean', $scanner->scan('date,revenue')->status);
        Log::shouldHaveReceived('warning')->with('ingest.upload_not_scanned', \Mockery::type('array'))->once();
    }

    public function test_the_release_command_migrates_as_the_owner_then_syncs_the_app_role(): void
    {
        $this->assertSame(0, Artisan::call('hub:release'));
        $output = Artisan::output();
        $this->assertStringContainsString('app_user password applied', $output);
    }
}
