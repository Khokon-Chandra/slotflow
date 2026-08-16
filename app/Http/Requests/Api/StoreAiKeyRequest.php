<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Models\TenantAiSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreAiKeyRequest extends FormRequest
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
            /*
             * No prefix rule.
             *
             * `starts_with:sk-ant-` would catch a pasted Stripe key without a
             * network round trip, and would also reject a perfectly good key
             * the day Anthropic changes its format. The API is the authority
             * on whether a credential works, and it is consulted before
             * anything is stored — so a wrong key fails with a clear message
             * either way, and a valid one is never refused by a guess about
             * its shape.
             */
            'api_key' => ['required', 'string', 'min:20', 'max:400', 'regex:/^\S+$/'],

            // Restricted to models we can price. Allowing anything else means
            // cost tracking silently reports zero, which is worse than
            // refusing the model.
            'model' => ['nullable', 'string', Rule::in(array_keys((array) config('ai.pricing', [])))],
        ];
    }

    public function messages(): array
    {
        return [
            'api_key.regex' => 'The key must not contain spaces or line breaks. Copy it again without the surrounding text.',
            'api_key.min' => 'That looks too short to be an API key.',
            'model.in' => 'That model is not one this application knows how to price. Pick one from the list.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // People paste keys with a trailing newline far more often than they
        // paste them cleanly.
        if (is_string($this->input('api_key'))) {
            $this->merge(['api_key' => trim($this->string('api_key')->toString())]);
        }
    }
}
