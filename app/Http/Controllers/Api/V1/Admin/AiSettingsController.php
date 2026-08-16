<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Ai\AiManager;
use App\Ai\Credentials\AiCredentials;
use App\Ai\Credentials\StoreApiKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAiKeyRequest;
use App\Http\Requests\Api\UpdateAiSettingsRequest;
use App\Http\Resources\AiSettingsResource;
use App\Models\TenantAiSettings;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin · AI credentials
 *
 * Where a workspace installs its own Anthropic key. Owner only.
 *
 * No endpoint here returns the key. The store endpoint verifies it against
 * the Anthropic API *before* writing, so a workspace is never left looking
 * configured while every call quietly falls back.
 */
final class AiSettingsController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AiCredentials $credentials,
        private readonly StoreApiKey $keys,
        private readonly AiManager $ai,
    ) {}

    /**
     * Current AI configuration
     *
     * The stored settings, what is actually in force after platform fallback,
     * and the models available to choose from.
     */
    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TenantAiSettings::class);

        return response()->json([
            'data' => [
                'settings' => new AiSettingsResource($this->settings()->load('setBy')),
                'effective' => $this->effective(),
                'available_models' => $this->credentials->availableModels(),
            ],
        ]);
    }

    /**
     * Install an API key
     *
     * The key is checked against the Anthropic API first. If the check fails
     * nothing is written and the response explains why, so a bad paste is a
     * message rather than a silently broken workspace.
     */
    public function storeKey(StoreAiKeyRequest $request): JsonResponse
    {
        ['settings' => $settings, 'verification' => $verification] = ($this->keys)(
            tenant: $this->tenants->require(),
            apiKey: $request->string('api_key')->toString(),
            model: $request->input('model'),
            actor: $request->user(),
        );

        if (! $verification->ok) {
            return response()->json([
                'error' => [
                    'code' => 'ai_key_rejected',
                    'message' => $verification->error,
                ],
            ], 422);
        }

        return response()->json([
            'data' => [
                'settings' => new AiSettingsResource($settings),
                'effective' => $this->effective(),
                'verification' => $verification->toArray(),
            ],
        ]);
    }

    /**
     * Remove the API key
     *
     * Model and budget preferences survive, so putting a key back later does
     * not mean reconfiguring everything else. AI features keep working —
     * they fall back to the platform key if one exists, and to the built-in
     * implementations if not.
     */
    public function destroyKey(Request $request): JsonResponse
    {
        $this->authorize('manage', TenantAiSettings::class);

        $this->keys->remove($this->tenants->require());

        return response()->json([
            'data' => [
                'settings' => new AiSettingsResource($this->settings()),
                'effective' => $this->effective(),
            ],
        ]);
    }

    /**
     * Re-check the stored key
     *
     * A key that verified when it was saved can be revoked later. This is how
     * an owner finds that out on purpose, rather than by noticing the AI has
     * gone quiet.
     */
    public function verify(Request $request): JsonResponse
    {
        $this->authorize('manage', TenantAiSettings::class);

        $verification = $this->keys->recheck($this->tenants->require());

        if ($verification === null) {
            return response()->json([
                'error' => [
                    'code' => 'no_key_installed',
                    'message' => 'This workspace has no key of its own to check.',
                ],
            ], 422);
        }

        return response()->json([
            'data' => [
                'settings' => new AiSettingsResource($this->settings()),
                'effective' => $this->effective(),
                'verification' => $verification->toArray(),
            ],
        ]);
    }

    /**
     * Update model and budget
     *
     * Both accept null, meaning "use the platform default".
     */
    public function update(UpdateAiSettingsRequest $request): JsonResponse
    {
        $settings = $this->settings();
        $settings->fill($request->validated());
        $settings->tenant_id = $this->tenants->require()->id;
        $settings->save();

        return response()->json([
            'data' => [
                'settings' => new AiSettingsResource($settings->refresh()),
                'effective' => $this->effective(),
            ],
        ]);
    }

    /**
     * The row for this workspace, created unsaved if it does not exist yet, so
     * every response has the same shape whether or not anything is configured.
     */
    private function settings(): TenantAiSettings
    {
        return TenantAiSettings::query()
            ->withoutTenantScope()
            ->firstOrNew(['tenant_id' => $this->tenants->require()->id]);
    }

    /**
     * What is actually in force, after workspace-then-platform fallback.
     *
     * @return array<string, mixed>
     */
    private function effective(): array
    {
        return [
            'driver' => $this->ai->isLive() ? 'claude' : 'heuristic',
            'key_source' => $this->credentials->source(),
            'model' => $this->credentials->model(),
            'monthly_budget_usd' => $this->credentials->monthlyBudgetUsd(),
            'configured_driver' => (string) config('ai.driver'),
        ];
    }
}
