<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
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
        return [
            'email' => ['required', 'email:rfc'],
            'password' => ['required', 'string'],
            // Named so the owner can revoke one client without logging out
            // every other. Sanctum tokens are cheap; anonymous ones are not.
            'device_name' => ['required', 'string', 'max:120'],
        ];
    }
}
