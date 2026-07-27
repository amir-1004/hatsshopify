<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Any HTTP request a test didn't explicitly fake is a bug: tests must
        // never reach Shopify, Anthropic, or Printful for real.
        Http::preventStrayRequests();

        // The unit-test CI job doesn't build frontend assets, so there's no
        // Vite manifest to resolve. Rendering real built assets is covered by
        // the http-smoke job, which boots the app after `npm run build`.
        $this->withoutVite();
    }
}
