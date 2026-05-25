<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on a tenant-owned model to the current tenant.
 *
 * When no tenant is bound (console commands, the seeder, the tenant-resolution
 * step itself) the scope is inert — it never silently returns rows from the
 * wrong business, it just doesn't filter. Anything that must be tenant-safe
 * runs behind middleware that binds one first.
 */
final class TenantScope implements Scope
{
    public const string IDENTIFIER = 'tenant';

    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(TenantContext::class)->id();

        if ($tenantId === null) {
            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
