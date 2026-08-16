<?php

declare(strict_types=1);

namespace App\Ai\Credentials;

use App\Models\TenantAiSettings;
use App\Support\TenantContext;

/**
 * Answers "which key, which model, which budget" for the current workspace.
 *
 * Resolution is workspace first, platform second:
 *
 *   1. the tenant's own key, set through the admin panel
 *   2. ANTHROPIC_API_KEY from .env
 *   3. nothing — the heuristic driver answers
 *
 * That order is the product decision. A business that brings its own key pays
 * its own bill and can be given its own budget; a single-tenant deployment
 * configures .env once and never sees the settings page. Neither needs to know
 * the other exists.
 *
 * Resolved per call rather than cached in a singleton. A queue worker handles
 * jobs for several workspaces in one process, and a cached key is a key used
 * for somebody else's tenant.
 */
final class AiCredentials
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function apiKey(): ?string
    {
        $tenantKey = $this->settings()?->api_key;

        if (filled($tenantKey)) {
            return $tenantKey;
        }

        $platformKey = config('ai.claude.api_key');

        return filled($platformKey) ? (string) $platformKey : null;
    }

    public function hasKey(): bool
    {
        return $this->apiKey() !== null;
    }

    /**
     * Where the key in use came from. Surfaced in the admin panel so an owner
     * can tell "the platform is paying" from "I am paying".
     *
     * @return 'tenant'|'platform'|'none'
     */
    public function source(): string
    {
        if (filled($this->settings()?->api_key)) {
            return 'tenant';
        }

        return filled(config('ai.claude.api_key')) ? 'platform' : 'none';
    }

    public function model(): string
    {
        $model = $this->settings()?->model;

        return filled($model) ? $model : (string) config('ai.claude.model');
    }

    public function monthlyBudgetUsd(): float
    {
        $budget = $this->settings()?->monthly_budget_usd;

        return $budget === null
            ? (float) config('ai.monthly_budget_usd', 25)
            : (float) $budget;
    }

    /**
     * The models a workspace may choose from: exactly those this application
     * has prices for.
     *
     * Allowing anything else means every call reports a spend of zero, which
     * is a worse outcome than refusing the model — a budget you cannot measure
     * is not a budget.
     *
     * @return list<array{id: string, input_per_mtok_usd: float, output_per_mtok_usd: float, is_platform_default: bool}>
     */
    public function availableModels(): array
    {
        /** @var array<string, array{input: float, output: float}> $pricing */
        $pricing = config('ai.pricing', []);
        $default = (string) config('ai.claude.model');

        $models = [];

        foreach ($pricing as $id => $rates) {
            $models[] = [
                'id' => (string) $id,
                'input_per_mtok_usd' => $rates['input'],
                'output_per_mtok_usd' => $rates['output'],
                'is_platform_default' => $id === $default,
            ];
        }

        return $models;
    }

    /**
     * The current workspace's row, or null.
     *
     * Not memoised: the settings page saves a key and immediately re-reads the
     * resolved state to show what is now in force, and a stale read there is a
     * page that lies about whether it worked.
     */
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
}
