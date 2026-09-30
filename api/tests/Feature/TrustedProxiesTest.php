<?php
// api/tests/Feature/TrustedProxiesTest.php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    private function scheme(): string
    {
        Route::get('/_scheme', fn () => request()->getScheme());

        return $this->get('/_scheme', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.9'])->getContent();
    }

    public function test_forwarded_headers_are_ignored_by_default(): void
    {
        $this->assertSame('http', $this->scheme());
    }

    public function test_the_load_balancer_is_trusted_when_configured(): void
    {
        putenv('TRUSTED_PROXIES=*');
        $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = '*';
        $this->refreshApplication();

        try {
            $this->assertSame('https', $this->scheme());
        } finally {
            putenv('TRUSTED_PROXIES');
            unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
        }
    }
}
