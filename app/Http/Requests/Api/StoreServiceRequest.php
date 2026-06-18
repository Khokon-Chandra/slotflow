<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\ScopesExistenceToTenant;
use App\Models\Service;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreServiceRequest extends FormRequest
{
    use ScopesExistenceToTenant;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Service::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'nullable', 'string', 'max:120', 'alpha_dash',
                // Unique per tenant, not globally — two businesses may both
                // offer a "consultation".
                Rule::unique('services', 'slug')->where('tenant_id', app(TenantContext::class)->id()),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'keywords' => ['nullable', 'string', 'max:500'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:600'],
            'buffer_minutes' => ['nullable', 'integer', 'min:0', 'max:180'],
            'price_cents' => ['required', 'integer', 'min:0', 'max:10000000'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => ['boolean'],
            'requires_deposit' => ['boolean'],
            'deposit_cents' => ['nullable', 'integer', 'min:0', 'lte:price_cents'],
            'staff_ids' => ['array'],
            'staff_ids.*' => ['integer', $this->existsInTenant('staff')],
        ];
    }
}
