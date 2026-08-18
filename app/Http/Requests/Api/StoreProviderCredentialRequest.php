<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Ai\Providers\Provider;
use App\Ai\Providers\ProviderRegistry;
use App\Models\TenantAiSettings;
use Illuminate\Foundation\Http\FormRequest;

final class StoreProviderCredentialRequest extends FormRequest
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
        $registry = app(ProviderRegistry::class);
        $provider = $registry->find((string) $this->route('provider'));

        return [
            /*
             * No key-format rule.
             *
             * A prefix check would catch a pasted Stripe key without a network
             * round trip, and would also reject a perfectly good key the day a
             * provider changes its format — or the first time someone connects
             * a self-hosted runtime that takes any string at all. The provider
             * is the authority on whether a credential works, and it is asked
             * before anything is stored.
             */
            'api_key' => ['required', 'string', 'min:8', 'max:400', 'regex:/^\S+$/'],

            'model' => ['required', 'string', 'max:96'],

            // Only the custom provider carries these; for the rest the
            // catalogue supplies the endpoint.
            'label' => [
                $provider?->isCustom() ? 'required' : 'nullable',
                'string', 'max:60',
            ],
            'base_url' => [
                $provider?->isCustom() ? 'required' : 'nullable',
                'string', 'url', 'max:255',
                // http is fine for a runtime on localhost; anything reachable
                // over the network must not carry a bearer token in the clear.
                'regex:/^https:\/\/|^http:\/\/(localhost|127\.0\.0\.1|\[::1\])(:\d+)?(\/|$)/i',
            ],

            // Optional overrides, so a workspace can track spend for a model
            // this application has no published rates for.
            'input_rate_per_mtok' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'output_rate_per_mtok' => ['nullable', 'numeric', 'min:0', 'max:10000'],

            'make_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'api_key.regex' => 'The key must not contain spaces or line breaks. Copy it again without the surrounding text.',
            'base_url.regex' => 'Use https, or http only for localhost. A bearer token must not travel over plain http.',
            'base_url.required' => 'A custom provider needs the base URL of an OpenAI-compatible API — usually ending in /v1.',
            'label.required' => 'Give this connection a name you will recognise.',
        ];
    }

    public function provider(): Provider
    {
        return app(ProviderRegistry::class)->require((string) $this->route('provider'));
    }

    protected function prepareForValidation(): void
    {
        // People paste keys with a trailing newline far more often than they
        // paste them cleanly.
        foreach (['api_key', 'model', 'base_url', 'label'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->string($field)->toString())]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function credentialInput(): array
    {
        $validated = $this->validated();

        return [
            'api_key' => $validated['api_key'],
            'model' => $validated['model'],
            'label' => $validated['label'] ?? null,
            'base_url' => $validated['base_url'] ?? null,
            'input_rate_per_mtok' => $validated['input_rate_per_mtok'] ?? null,
            'output_rate_per_mtok' => $validated['output_rate_per_mtok'] ?? null,
            'make_active' => $this->boolean('make_active', true),
        ];
    }

    /**
     * The provider id must exist in the catalogue before anything else runs.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! app(ProviderRegistry::class)->has((string) $this->route('provider'))) {
                $validator->errors()->add('provider', 'That provider is not one this application knows how to talk to.');
            }
        });
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return ['base_url' => 'base URL', 'input_rate_per_mtok' => 'input rate', 'output_rate_per_mtok' => 'output rate'];
    }
}
