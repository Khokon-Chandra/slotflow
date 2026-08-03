<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;

final class ServiceDescriptionRequest extends FormRequest
{
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
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:600'],
            'price_cents' => ['required', 'integer', 'min:0'],
            'audience' => ['nullable', 'string', 'max:200'],
            'keywords' => ['nullable', 'string', 'max:200'],
            'tone' => ['nullable', 'string', 'max:60'],
        ];
    }
}
