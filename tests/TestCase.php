<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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
