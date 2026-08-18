<?php

declare(strict_types=1);

namespace App\Ai\Credentials;

use App\Ai\Providers\Provider;
use App\Ai\Providers\ProviderRegistry;
use App\Models\AiProviderCredential;
use App\Models\TenantAiSettings;
use App\Support\TenantContext;

/**
 * Answers "which provider, which key, which model, which budget" for the
 * current workspace.
 *
 * Resolution is workspace first, platform second:
 *
 *   1. the workspace's active credential, connected in the admin panel
 *   2. the platform credential from .env
 *   3. nothing — the heuristic driver answers
 *
 * That order is the product decision. A business that connects its own
 * provider pays its own bill and gets its own ceiling; a single-tenant
 * deployment configures .env once and never opens the page. Neither needs to
 * know the other exists.
 *
 * Resolved per call, never memoised. A queue worker serves several workspaces
 * in one process, and a cached credential is a credential used for the wrong
 * tenant.
 */
final class AiCredentials
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ProviderRegistry $providers,
    ) {}

    public function resolve(): ?ResolvedCredential
    {
        return $this->fromWorkspace() ?? $this->fromPlatform();
    }

    public function hasCredential(): bool
    {
        return $this->resolve() !== null;
    }

    /**
     * Where the credential in use came from. Surfaced in the admin panel so an
     * owner can tell "the platform is paying" from "I am paying".
     *
     * @return 'workspace'|'platform'|'none'
     */
    public function source(): string
    {
        return $this->resolve()->source ?? 'none';
    }

    public function provider(): ?Provider
    {
        return $this->resolve()?->provider;
    }

    public function model(): ?string
    {
        return $this->resolve()?->model;
    }

    public function monthlyBudgetUsd(): float
    {
        $budget = $this->settings()?->monthly_budget_usd;

        return $budget === null
            ? (float) config('ai.monthly_budget_usd', 25)
            : (float) $budget;
    }

    /**
     * Every credential this workspace has connected, active first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, AiProviderCredential>
     */
    public function all()
    {
        $tenantId = $this->tenants->id();

        return AiProviderCredential::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->with('setBy')
            ->orderByDesc('is_active')
            ->orderBy('provider')
            ->get();
    }

    public function activeCredential(): ?AiProviderCredential
    {
        $tenantId = $this->tenants->id();

        if ($tenantId === null) {
            return null;
        }

        return AiProviderCredential::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->active()
            ->first();
    }

    public function settings(): ?TenantAiSettings
    {
        $tenantId = $this->tenants->id();

        if ($tenantId === null) {
            return null;
        }

        return TenantAiSettings::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->first();
    }

    private function fromWorkspace(): ?ResolvedCredential
    {
        $credential = $this->activeCredential();

        if ($credential === null || ! $credential->hasKey()) {
            return null;
        }

        if (! $this->providers->has($credential->provider)) {
            // The provider was removed from the catalogue after the workspace
            // connected it. Falling back beats calling an endpoint nothing in
            // this application knows the shape of.
            return null;
        }

        return new ResolvedCredential(
            provider: $credential->provider(),
            apiKey: (string) $credential->api_key,
            model: $credential->model,
            baseUrl: $credential->endpoint(),
            source: 'workspace',
            rates: $credential->rates(),
            label: $credential->displayName(),
        );
    }

    private function fromPlatform(): ?ResolvedCredential
    {
        $key = config('ai.platform.api_key');

        if (blank($key)) {
            return null;
        }

        $provider = $this->providers->find((string) config('ai.platform.provider', 'anthropic'));

        if ($provider === null) {
            return null;
        }

        $model = (string) config('ai.platform.model', $provider->defaultModel());

        return new ResolvedCredential(
            provider: $provider,
            apiKey: (string) $key,
            model: $model,
            baseUrl: config('ai.platform.base_url') ?: $provider->baseUrl,
            source: 'platform',
            rates: $provider->rates($model),
        );
    }
}
