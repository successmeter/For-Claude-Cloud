<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach the network. Tests that expect an outbound call fake it (the HIBP
        // password check, Hub webhook delivery); anything else fails loudly instead.
        Http::preventStrayRequests();
    }
}
