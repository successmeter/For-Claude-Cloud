<?php
// api/config/kms.php
return [
    // 'local' (development and tests only) or 'aws' (AWS KMS; per-org keys in org_data_keys).
    'driver' => env('KMS_DRIVER', 'local'),
    'aws_key_id' => env('KMS_KEY_ID'),
    'aws_region' => env('KMS_REGION', env('AWS_DEFAULT_REGION', 'ap-southeast-2')),

    // 64 hex chars (32 bytes). Generate with: sodium_bin2hex(sodium_crypto_secretbox_keygen())
    'master_key' => env('KMS_MASTER_KEY'),
];
