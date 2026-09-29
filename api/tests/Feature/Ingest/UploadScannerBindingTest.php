<?php
// api/tests/Feature/Ingest/UploadScannerBindingTest.php

namespace Tests\Feature\Ingest;

use App\Ingest\Scanning\ClamAvUploadScanner;
use App\Ingest\Scanning\NullUploadScanner;
use App\Ingest\Scanning\UploadScanner;
use Tests\TestCase;

class UploadScannerBindingTest extends TestCase
{
    public function test_tests_use_the_null_scanner(): void
    {
        $this->assertInstanceOf(NullUploadScanner::class, app(UploadScanner::class));
    }

    public function test_clamav_is_the_default(): void
    {
        config(['ingest.scanner' => 'clamav']);

        $this->assertInstanceOf(ClamAvUploadScanner::class, app(UploadScanner::class));
    }
}
