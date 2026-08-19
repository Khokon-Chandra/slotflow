<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * The suite must not touch the network — that is what makes it
         * runnable with no secret, in CI, on a plane.
         *
         * This turns the claim into a guarantee: any request through Laravel's
         * HTTP client that a test has not explicitly faked fails loudly and
         * names the URL, instead of quietly taking two seconds and depending
         * on somebody else's uptime. It found one such call the day it was
         * added.
         *
         * It does not cover the Anthropic SDK, which uses Guzzle directly —
         * that path is kept off the network by AI_DRIVER=heuristic and by
         * faking the verifier.
         */
        Http::preventStrayRequests();
    }

    /**
     * Bind a tenant for the duration of a test.
     *
     * Almost every model in this application is behind a tenant global scope,
     * so a test that forgets this sees an empty database and a confusing
     * failure.
     */
    protected function actingForTenant(Tenant $tenant): Tenant
    {
        app(TenantContext::class)->set($tenant);

        return $tenant;
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->forget();

        parent::tearDown();
    }
}
