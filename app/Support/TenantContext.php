<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use RuntimeException;

/**
 * Holds the tenant for the current request (or console command).
 *
 * Registered as a singleton in App\Providers\AppServiceProvider. Everything
 * that needs to know "which business are we?" reads it from here rather than
 * threading a $tenantId parameter through fifteen call sites.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function forget(): void
    {
        $this->tenant = null;
    }

    public function has(): bool
    {
        return $this->tenant instanceof Tenant;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    /**
     * The tenant, or a hard failure. Use this where a missing tenant is a bug
     * rather than a valid state — a silent null here becomes cross-tenant data
     * leakage three layers down.
     */
    public function require(): Tenant
    {
        if (! $this->tenant instanceof Tenant) {
            throw new RuntimeException(
                'No tenant is bound to the current context. '
                .'Did the request skip the resolve.tenant middleware?'
            );
        }

        return $this->tenant;
    }

    /**
     * Run a callback with a different tenant bound, then restore the previous
     * one. Used by the seeder, the scheduler and the test suite.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runFor(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
        }
    }
}
