<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\ScopesExistenceToTenant;
use App\Models\Staff;
use App\Rules\ValidTimezone;
use Illuminate\Foundation\Http\FormRequest;

final class StoreStaffRequest extends FormRequest
{
    use ScopesExistenceToTenant;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Staff::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:120'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'avatar_url' => ['nullable', 'url', 'max:500'],
            'timezone' => ['required', 'string', new ValidTimezone],
            'is_active' => ['boolean'],
            'service_ids' => ['array'],
            'service_ids.*' => ['integer', $this->existsInTenant('services')],
        ];
    }
}
