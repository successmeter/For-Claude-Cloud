<?php
// api/config/kms.php
return [
    // 64 hex chars (32 bytes). Generate with: sodium_bin2hex(sodium_crypto_secretbox_keygen())
    'master_key' => env('KMS_MASTER_KEY'),
];
