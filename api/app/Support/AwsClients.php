<?php
// api/app/Support/AwsClients.php
namespace App\Support;

use Aws\Kms\KmsClient;

/** AWS clients the Hub builds itself (object storage clients come from the filesystem config). */
final class AwsClients
{
    /** KMS with its own keys when set (Laravel Cloud), else the default chain (the ECS task role). */
    public static function kms(): KmsClient
    {
        $options = ['region' => config('kms.aws_region'), 'version' => '2014-11-01'];
        if (filled(config('kms.aws_access_key_id')) && filled(config('kms.aws_secret_access_key'))) {
            $options['credentials'] = ['key' => config('kms.aws_access_key_id'), 'secret' => config('kms.aws_secret_access_key')];
        }

        return new KmsClient($options);
    }
}
