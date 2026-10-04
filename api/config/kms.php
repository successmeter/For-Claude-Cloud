<?php
// api/config/kms.php
return [
    // 'local' (development and tests only) or 'aws' (AWS KMS; per-org keys in org_data_keys).
    'driver' => env('KMS_DRIVER', 'local'),
    'aws_key_id' => env('KMS_KEY_ID'),
    // Object storage on some hosts sets AWS_DEFAULT_REGION=auto, which is no KMS region.
    'aws_region' => env('KMS_REGION') ?: (in_array(env('AWS_DEFAULT_REGION'), [null, '', 'auto'], true) ? 'ap-southeast-2' : env('AWS_DEFAULT_REGION')),
    // Outside AWS (Laravel Cloud), an IAM user's keys for KMS only, kept apart from the AWS_* keys
    // the host gives its object storage. Unset on AWS: the task role's credentials are used.
    'aws_access_key_id' => env('KMS_AWS_ACCESS_KEY_ID'),
    'aws_secret_access_key' => env('KMS_AWS_SECRET_ACCESS_KEY'),

    // 64 hex chars (32 bytes). Generate with: sodium_bin2hex(sodium_crypto_secretbox_keygen())
    'master_key' => env('KMS_MASTER_KEY'),
];
