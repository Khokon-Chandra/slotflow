<?php

declare(strict_types=1);

namespace App\Ai\Credentials;

use App\Ai\Credentials\Contracts\VerifiesApiKeys;
use App\Models\Tenant;
use App\Models\TenantAiSettings;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Verifies a key, then stores it — in that order, never the other way round.
 *
 * The only path from a request body to `tenant_ai_settings.api_key`. The
 * column is not mass-assignable, so there is no second route to keep in step
 * with this one.
 */
final class StoreApiKey
{
    public function __construct(
        private readonly VerifiesApiKeys $verify,
        private readonly ClaudeClientFactory $clients,
    ) {}

    /**
     * @return array{settings: TenantAiSettings|null, verification: KeyVerification}
     */
    public function __invoke(Tenant $tenant, string $apiKey, ?string $model, User $actor): array
    {
        $apiKey = trim($apiKey);
        $model = filled($model) ? $model : (string) config('ai.claude.model');

        $verification = ($this->verify)($apiKey, $model);

        if (! $verification->ok) {
            // Nothing is written. A workspace that looks configured but falls
            // back on every call is worse than one that is plainly unconfigured.
            return ['settings' => null, 'verification' => $verification];
        }

        /** @var TenantAiSettings $settings */
        $settings = TenantAiSettings::query()
            ->withoutTenantScope()
            ->firstOrNew(['tenant_id' => $tenant->id]);

        // Replacing a key must not leave the previous one usable from the
        // in-process client cache.
        if (filled($settings->api_key)) {
            $this->clients->forget((string) $settings->api_key);
        }

        $settings->tenant_id = $tenant->id;
        $settings->api_key = $apiKey;
        $settings->key_last_four = substr($apiKey, -4);
        $settings->key_set_at = CarbonImmutable::now();
        $settings->key_set_by = $actor->id;
        $settings->verified_at = CarbonImmutable::now();
        $settings->verification_error = null;
        $settings->model = $model;
        $settings->save();

        return ['settings' => $settings->refresh(), 'verification' => $verification];
    }

    /**
     * Remove the workspace's key. Model and budget preferences survive, so
     * putting a key back does not mean reconfiguring everything else.
     */
    public function remove(Tenant $tenant): void
    {
        $settings = TenantAiSettings::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->first();

        if ($settings === null) {
            return;
        }

        if (filled($settings->api_key)) {
            $this->clients->forget((string) $settings->api_key);
        }

        $settings->api_key = null;
        $settings->key_last_four = null;
        $settings->key_set_at = null;
        $settings->key_set_by = null;
        $settings->verified_at = null;
        $settings->verification_error = null;
        $settings->save();
    }

    /**
     * Re-check the stored key and record the outcome.
     *
     * A key that verified when it was saved can be revoked later. This is how
     * an owner finds that out on purpose rather than by noticing the AI has
     * gone quiet.
     */
    public function recheck(Tenant $tenant): ?KeyVerification
    {
        $settings = TenantAiSettings::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->first();

        if ($settings === null || ! $settings->hasKey()) {
            return null;
        }

        $verification = ($this->verify)(
            (string) $settings->api_key,
            filled($settings->model) ? $settings->model : (string) config('ai.claude.model'),
        );

        $settings->verified_at = CarbonImmutable::now();
        $settings->verification_error = $verification->ok ? null : $verification->error;
        $settings->save();

        return $verification;
    }
}
