<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Rules\ValidTimezone;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // `id()`, not `require()`: the OpenAPI generator evaluates rules()
        // statically, outside any request, where no tenant is bound. Hard
        // failure there costs a documented request body for no benefit — and
        // inside a real request the middleware has always bound one.
        $tenantId = app(TenantContext::class)->id();

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required', 'email:rfc', 'max:190',
                Rule::unique('users', 'email')->where('tenant_id', $tenantId),
            ],
            'password' => ['required', 'confirmed', Password::defaults()],
            'phone' => ['nullable', 'string', 'max:32'],
            'timezone' => ['required', 'string', new ValidTimezone],
        ];
    }
}
