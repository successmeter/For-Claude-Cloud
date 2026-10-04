<?php
// api/config/ingest.php
// Sales uploads (Plan C design §4).
return [
    'max_bytes' => 2 * 1024 * 1024,
    'max_rows' => 5000,

    // Unfinished (previewed) uploads expire after this many hours; snapshots after this many days.
    'run_hours' => 24,
    'snapshot_days' => 90,

    // 'clamav' in every deployed environment; 'none' only for local development and tests. 'off' is a
    // deliberate choice for a host that can't run clamd (the Laravel Cloud trial): uploads are only
    // ever parsed as CSV, never stored as sent or served back, and each one is logged as not scanned.
    'scanner' => env('INGEST_SCANNER', 'clamav'),
    'clamd' => [
        'address' => env('CLAMD_ADDRESS', 'unix:///var/run/clamav/clamd.ctl'),
        'timeout' => (int) env('CLAMD_TIMEOUT', 10),
    ],
];
