<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\ScopesExistenceToTenant;
use App\Models\Service;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateServiceRequest extends FormRequest
{
    use ScopesExistenceToTenant;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('service')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Service|null $service */
        $service = $this->route('service');

        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'slug' => [
                'sometimes', 'string', 'max:120', 'alpha_dash',
                Rule::unique('services', 'slug')
                    ->where('tenant_id', app(TenantContext::class)->id())
                    // Null only when rules() is evaluated outside a request,
                    // which is how the OpenAPI generator reads this file.
                    ->ignore($service?->id),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'keywords' => ['nullable', 'string', 'max:500'],
            'duration_minutes' => ['sometimes', 'integer', 'min:5', 'max:600'],
            'buffer_minutes' => ['nullable', 'integer', 'min:0', 'max:180'],
            'price_cents' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => ['boolean'],
            'requires_deposit' => ['boolean'],
            'deposit_cents' => ['nullable', 'integer', 'min:0'],
            'staff_ids' => ['array'],
            'staff_ids.*' => ['integer', $this->existsInTenant('staff')],
        ];
    }
}
