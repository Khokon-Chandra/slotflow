<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Models\TenantAiSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateAiSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', TenantAiSettings::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'model' => ['nullable', 'string', Rule::in(array_keys((array) config('ai.pricing', [])))],

            // Null means "use the platform default". Zero means "no ceiling",
            // which is a decision an owner is allowed to make explicitly but
            // will never arrive at by accident.
            'monthly_budget_usd' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ];
    }
}
