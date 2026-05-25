<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every business-owned model.
 *
 * Two jobs:
 *   1. read  — a global scope that adds `where tenant_id = ?`
 *   2. write — fill `tenant_id` on create, so no caller has to remember
 *
 * Forgetting a where clause is the single most common way a multi-tenant app
 * leaks data. Making it the default, and the escape hatch explicit
 * (`withoutTenantScope()`), inverts that risk.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', app(TenantContext::class)->id());
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The owning tenant, without a query.
     *
     * Every record in a request belongs to the same business, and the
     * middleware already loaded it. Reading `$service->tenant` instead would
     * be a lazy load per row — invisible on six seeded services, four hundred
     * queries on a real catalogue. Strict mode turns that mistake into a
     * failing test rather than a slow page, which is why this accessor exists
     * at all.
     */
    public function tenantModel(): Tenant
    {
        $current = app(TenantContext::class)->get();

        if ($current instanceof Tenant && $current->id === $this->tenant_id) {
            return $current;
        }

        /** @var Tenant $tenant */
        $tenant = $this->loadMissing('tenant')->getRelation('tenant');

        return $tenant;
    }

    /**
     * Deliberately cross-tenant. Every call site is a place to look twice.
     */
    public function scopeWithoutTenantScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }

    public function scopeForTenant(Builder $query, Tenant|int $tenant): Builder
    {
        return $query
            ->withoutGlobalScope(TenantScope::class)
            ->where($this->qualifyColumn('tenant_id'), $tenant instanceof Tenant ? $tenant->id : $tenant);
    }
}
