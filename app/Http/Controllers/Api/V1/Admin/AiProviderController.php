<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Ai\AiManager;
use App\Ai\Credentials\AiCredentials;
use App\Ai\Credentials\StoreProviderCredential;
use App\Ai\Providers\ProviderRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreProviderCredentialRequest;
use App\Http\Requests\Api\UpdateAiSettingsRequest;
use App\Http\Resources\AiProviderCredentialResource;
use App\Models\AiProviderCredential;
use App\Models\TenantAiSettings;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin · AI providers
 *
 * Where a workspace connects a model provider — Anthropic, OpenAI, DeepSeek,
 * or any endpoint that speaks the OpenAI Chat Completions shape. Owner only.
 *
 * No endpoint here returns a key. Every store call verifies against the
 * provider *before* writing, so a workspace is never left looking configured
 * while every call quietly falls back.
 */
final class AiProviderController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AiCredentials $credentials,
        private readonly StoreProviderCredential $store,
        private readonly ProviderRegistry $registry,
        private readonly AiManager $ai,
    ) {}

    /**
     * Connected providers and the catalogue
     *
     * What this workspace has connected, which one is in force after platform
     * fallback, and every provider it could connect.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TenantAiSettings::class);

        return response()->json(['data' => $this->state()]);
    }

    /**
     * Connect a provider
     *
     * The credential is checked against the provider first. If the check
     * fails, nothing is written and the response explains why — a bad paste is
     * a message rather than a silently broken workspace.
     *
     * The first provider a workspace connects becomes the one in force.
     */
    public function store(StoreProviderCredentialRequest $request, string $provider): JsonResponse
    {
        ['credential' => $credential, 'verification' => $verification] = ($this->store)(
            tenant: $this->tenants->require(),
            provider: $request->provider(),
            input: $request->credentialInput(),
            actor: $request->user(),
        );

        if (! $verification->ok) {
            return response()->json([
                'error' => [
                    'code' => 'ai_credential_rejected',
                    'message' => $verification->error,
                ],
            ], 422);
        }

        return response()->json([
            'data' => [
                ...$this->state(),
                'verification' => $verification->toArray(),
            ],
        ]);
    }

    /**
     * Make a provider the one in force
     *
     * Exactly one credential is active at a time; the rest are demoted in the
     * same transaction.
     */
    public function activate(Request $request, string $provider): JsonResponse
    {
        $this->authorize('manage', TenantAiSettings::class);

        $this->store->activate($this->tenants->require(), $this->credential($provider));

        return response()->json(['data' => $this->state()]);
    }

    /**
     * Re-check a stored credential
     *
     * One that verified when it was saved can be revoked later. This is how an
     * owner finds that out on purpose, rather than by noticing the AI has gone
     * quiet.
     */
    public function verify(Request $request, string $provider): JsonResponse
    {
        $this->authorize('manage', TenantAiSettings::class);

        $verification = $this->store->recheck($this->credential($provider));

        return response()->json([
            'data' => [
                ...$this->state(),
                'verification' => $verification->toArray(),
            ],
        ]);
    }

    /**
     * Disconnect a provider
     *
     * If it was the one in force, the next verified credential takes over.
     * Nothing breaks either way: with no credential at all, AI features fall
     * back to the built-in implementations.
     */
    public function destroy(Request $request, string $provider): JsonResponse
    {
        $this->authorize('manage', TenantAiSettings::class);

        $this->store->remove($this->tenants->require(), $this->credential($provider));

        return response()->json(['data' => $this->state()]);
    }

    /**
     * Update workspace AI preferences
     *
     * Currently the monthly spend ceiling. Null means the platform default.
     */
    public function updateSettings(UpdateAiSettingsRequest $request): JsonResponse
    {
        $settings = TenantAiSettings::query()
            ->withoutTenantScope()
            ->firstOrNew(['tenant_id' => $this->tenants->require()->id]);

        $settings->fill($request->validated());
        $settings->tenant_id = $this->tenants->require()->id;
        $settings->save();

        return response()->json(['data' => $this->state()]);
    }

    private function credential(string $provider): AiProviderCredential
    {
        return AiProviderCredential::query()
            ->withoutTenantScope()
            ->where('tenant_id', $this->tenants->require()->id)
            ->where('provider', $provider)
            ->firstOrFail();
    }

    /**
     * The whole picture, returned by every endpoint here.
     *
     * One shape for every response means the client never has to merge a
     * partial update into its own idea of the state — which is where a
     * settings screen usually starts lying about what is configured.
     *
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $resolved = $this->credentials->resolve();
        $settings = $this->credentials->settings();

        return [
            'connected' => AiProviderCredentialResource::collection($this->credentials->all()),

            'effective' => [
                'driver' => $this->ai->isLive() ? ($resolved?->provider->id ?? 'heuristic') : 'heuristic',
                'source' => $this->credentials->source(),
                'provider' => $resolved?->provider->id,
                'provider_label' => $resolved?->displayName(),
                'model' => $resolved?->model,
                'tracks_spend' => $resolved?->tracksSpend() ?? false,
                'monthly_budget_usd' => $this->credentials->monthlyBudgetUsd(),
                'configured_driver' => (string) config('ai.driver'),
            ],

            'settings' => [
                'monthly_budget_usd' => $settings?->monthly_budget_usd === null
                    ? null
                    : (float) $settings->monthly_budget_usd,
            ],

            'catalogue' => $this->registry->toArray(),
        ];
    }
}
