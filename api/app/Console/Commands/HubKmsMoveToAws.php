<?php

// api/app/Console/Commands/HubKmsMoveToAws.php

namespace App\Console\Commands;

use App\Services\Encryption\AwsKmsDriver;
use App\Services\Encryption\EnvKeyKmsDriver;
use App\Services\Encryption\KeyManagementService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Launch step: re-wraps every trial data key (KMS_ENV_KEY) with AWS KMS. Run with KMS_DRIVER=aws and
 * KMS_ENV_KEY still set; delete KMS_ENV_KEY once it reports none left. Safe to run again.
 */
class HubKmsMoveToAws extends Command
{
    protected $signature = 'hub:kms-move-to-aws';

    protected $description = 'Move the trial data keys from KMS_ENV_KEY to AWS KMS';

    public function handle(KeyManagementService $kms): int
    {
        if (config('kms.driver') !== 'aws' || ! $kms instanceof AwsKmsDriver || ! $kms->canReadEnvKeys()) {
            $this->error('Set KMS_DRIVER=aws and the KMS_* AWS settings, keep KMS_ENV_KEY set, deploy, then run this again.');

            return self::FAILURE;
        }

        // Keys exist only for orgs that connected a POS or uploaded a file; both are listed outside RLS.
        $orgIds = DB::table('pos_orgs')->pluck('org_id')->merge(DB::table('ingest_orgs')->pluck('org_id'))->unique();
        $moved = 0;
        $left = 0;
        foreach ($orgIds as $orgId) {
            TenantContext::run($orgId, function () use ($kms, $orgId, &$moved, &$left) {
                $row = DB::table('org_data_keys')->where('org_id', $orgId)->first();
                if ($row === null || $row->wrapped_key === null || ! EnvKeyKmsDriver::wraps($row->kms_key_id)) {
                    return;
                }
                $kms->getDataKey($orgId);
                EnvKeyKmsDriver::wraps(DB::table('org_data_keys')->where('org_id', $orgId)->value('kms_key_id')) ? $left++ : $moved++;
            });
        }

        $this->info("Moved {$moved} keys to AWS KMS; {$left} left.");

        return $left === 0 ? self::SUCCESS : self::FAILURE;
    }
}
