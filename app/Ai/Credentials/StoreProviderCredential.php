<?php

declare(strict_types=1);

namespace App\Ai\Credentials;

use App\Ai\Credentials\Contracts\VerifiesCredentials;
use App\Ai\Providers\Provider;
use App\Models\AiProviderCredential;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Verifies a credential, then stores it — in that order, never the other way
 * round.
 *
 * The only path from a request body to `ai_provider_credentials.api_key`. The
 * column is not mass-assignable, so there is no second route to keep in step
 * with this one.
 */
final class StoreProviderCredential
{
    public function __construct(
        private readonly VerifiesCredentials $verify,
        private readonly AnthropicClientFactory $anthropic,
    ) {}

    /**
     * @param  array{api_key: string, model: string, label?: string|null, base_url?: string|null, input_rate_per_mtok?: float|null, output_rate_per_mtok?: float|null, make_active?: bool}  $input
     * @return array{credential: AiProviderCredential|null, verification: KeyVerification}
     */
    public function __invoke(Tenant $tenant, Provider $provider, array $input, User $actor): array
    {
        $apiKey = trim($input['api_key']);
        $model = trim($input['model']);
        $baseUrl = isset($input['base_url']) && filled($input['base_url'])
            ? rtrim(trim($input['base_url']), '/')
            : null;

        $verification = ($this->verify)($provider, $apiKey, $model, $baseUrl);

        if (! $verification->ok) {
            // Nothing is written. A workspace that looks configured but falls
            // back on every call is worse than one plainly unconfigured.
            return ['credential' => null, 'verification' => $verification];
        }

        $credential = DB::transaction(function () use ($tenant, $provider, $input, $actor, $apiKey, $model, $baseUrl): AiProviderCredential {
            /** @var AiProviderCredential $credential */
            $credential = AiProviderCredential::query()
                ->withoutTenantScope()
                ->firstOrNew(['tenant_id' => $tenant->id, 'provider' => $provider->id]);

            // Replacing a key must not leave the previous one usable from the
            // in-process client cache.
            if (filled($credential->api_key)) {
                $this->anthropic->forget((string) $credential->api_key);
            }

            $credential->tenant_id = $tenant->id;
            $credential->provider = $provider->id;
            $credential->label = $input['label'] ?? null;
            $credential->base_url = $baseUrl;
            $credential->api_key = $apiKey;
            $credential->key_last_four = substr($apiKey, -4);
            $credential->model = $model;
            $credential->input_rate_per_mtok = $input['input_rate_per_mtok'] ?? null;
            $credential->output_rate_per_mtok = $input['output_rate_per_mtok'] ?? null;
            $credential->key_set_at = CarbonImmutable::now();
            $credential->key_set_by = $actor->id;
            $credential->verified_at = CarbonImmutable::now();
            $credential->verification_error = null;

            // The first credential a workspace connects becomes the active one
            // — otherwise an owner adds a key, nothing changes, and the
            // obvious conclusion is that it did not work.
            $isFirst = ! AiProviderCredential::query()
                ->withoutTenantScope()
                ->where('tenant_id', $tenant->id)
                ->whereKeyNot($credential->id ?? 0)
                ->exists();

            $credential->is_active = $input['make_active'] ?? ($isFirst || $credential->is_active);
            $credential->save();

            if ($credential->is_active) {
                $this->demoteOthers($tenant, $credential);
            }

            return $credential;
        });

        return ['credential' => $credential->refresh(), 'verification' => $verification];
    }

    /**
     * Make one credential the one in force.
     */
    public function activate(Tenant $tenant, AiProviderCredential $credential): void
    {
        DB::transaction(function () use ($tenant, $credential): void {
            $credential->is_active = true;
            $credential->save();

            $this->demoteOthers($tenant, $credential);
        });
    }

    /**
     * Disconnect a provider.
     *
     * If it was the one in force, the next verified credential takes over.
     * Leaving a workspace with several keys and none active would be a silent
     * downgrade to the fallback.
     */
    public function remove(Tenant $tenant, AiProviderCredential $credential): void
    {
        DB::transaction(function () use ($tenant, $credential): void {
            $wasActive = $credential->is_active;

            if (filled($credential->api_key)) {
                $this->anthropic->forget((string) $credential->api_key);
            }

            $credential->delete();

            if (! $wasActive) {
                return;
            }

            $next = AiProviderCredential::query()
                ->withoutTenantScope()
                ->where('tenant_id', $tenant->id)
                ->orderByDesc('verified_at')
                ->first();

            if ($next !== null) {
                // Assigned rather than mass-assigned: `is_active` is not
                // fillable on purpose, so `update()` would discard it — and
                // outside strict mode it would do so without a word.
                $next->is_active = true;
                $next->save();
            }
        });
    }

    /**
     * Re-check a stored credential and record the outcome.
     *
     * One that verified on Tuesday can be revoked on Wednesday. This is how an
     * owner finds that out on purpose, rather than by noticing the AI has gone
     * quiet.
     */
    public function recheck(AiProviderCredential $credential): KeyVerification
    {
        $verification = ($this->verify)(
            $credential->provider(),
            (string) $credential->api_key,
            $credential->model,
            $credential->base_url,
        );

        $credential->verified_at = CarbonImmutable::now();
        $credential->verification_error = $verification->ok ? null : $verification->error;
        $credential->save();

        return $verification;
    }

    private function demoteOthers(Tenant $tenant, AiProviderCredential $keep): void
    {
        AiProviderCredential::query()
            ->withoutTenantScope()
            ->where('tenant_id', $tenant->id)
            ->whereKeyNot($keep->id)
            ->update(['is_active' => false]);
    }
}
