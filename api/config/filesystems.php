<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        // Encrypted upload snapshots (Plan C design §3.4), already envelope-encrypted by the app. Local
        // disk in development. SNAPSHOTS_DRIVER=s3: on AWS an S3 bucket in Sydney with the task role's
        // credentials and KMS encryption at rest; on Laravel Cloud its object storage (the AWS_* bucket,
        // endpoint and keys it injects, and SNAPSHOTS_SSE=none: that storage has no AWS KMS).
        'snapshots' => env('SNAPSHOTS_DRIVER', 'local') === 's3' ? [
            'driver' => 's3',
            'bucket' => env('SNAPSHOTS_BUCKET') ?: env('AWS_BUCKET'),
            'region' => env('SNAPSHOTS_REGION') ?: env('AWS_DEFAULT_REGION', 'ap-southeast-2'),
            'endpoint' => env('SNAPSHOTS_ENDPOINT') ?: (env('AWS_ENDPOINT') ?: null),
            'use_path_style_endpoint' => (bool) env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => true,
            'report' => false,
            'options' => env('SNAPSHOTS_SSE') === 'none' ? [] : ['ServerSideEncryption' => 'aws:kms'],
        ] : [
            'driver' => 'local',
            'root' => env('SNAPSHOTS_ROOT', storage_path('app/snapshots')),
            'throw' => true,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
