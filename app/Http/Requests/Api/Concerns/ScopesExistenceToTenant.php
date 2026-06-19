<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Concerns;

use App\Support\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * `exists:services,id` runs a raw query and ignores Eloquent's tenant scope.
 *
 * Left alone, that means a request naming another business's service id passes
 * validation and only fails later at the lookup. Nothing is disclosed and
 * nothing is written — but "valid id, then 404" and "invalid id" are
 * distinguishable, and that difference is enough to enumerate which ids exist
 * somewhere on the platform.
 *
 * Scoping the rule closes it: an id belonging to another tenant is simply not
 * a valid id.
 *
 * @phpstan-require-extends \Illuminate\Foundation\Http\FormRequest
 */
trait ScopesExistenceToTenant
{
    protected function existsInTenant(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)
            ->where('tenant_id', app(TenantContext::class)->id());
    }
}
