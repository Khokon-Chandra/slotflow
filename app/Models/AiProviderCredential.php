<?php

declare(strict_types=1);

namespace App\Models;

use App\Ai\Providers\Provider;
use App\Ai\Providers\ProviderRegistry;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A workspace's credential for one model provider.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $provider
 * @property string|null $label only for the custom provider
 * @property string|null $base_url only for the custom provider
 * @property string|null $api_key decrypted on read; never serialised
 * @property string|null $key_last_four
 * @property string $model
 * @property float|string|null $input_rate_per_mtok
 * @property float|string|null $output_rate_per_mtok
 * @property bool $is_active
 * @property CarbonImmutable|null $key_set_at
 * @property int|null $key_set_by
 * @property CarbonImmutable|null $verified_at
 * @property string|null $verification_error
 * @property-read User|null $setBy
 */
/*
 * `api_key` is absent from Fillable on purpose. The only path to the column is
 * App\Ai\Credentials\StoreProviderCredential, which verifies the key against
 * the provider first — so there is no second route to keep in step with it.
 */
#[Fillable([
    'tenant_id', 'provider', 'label', 'base_url', 'model',
    'input_rate_per_mtok', 'output_rate_per_mtok',
])]
#[Hidden(['api_key'])]
class AiProviderCredential extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            // Encrypted at rest with APP_KEY. Rotating it without
            // re-encrypting makes stored keys unreadable — see docs/AI.md.
            'api_key' => 'encrypted',
            'is_active' => 'boolean',
            'key_set_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
        ];
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'key_set_by');
    }

    public function provider(): Provider
    {
        return app(ProviderRegistry::class)->require($this->provider);
    }

    /**
     * The name to show. Falls back to the catalogue label, so a custom
     * endpoint is whatever the owner called it.
     */
    public function displayName(): string
    {
        return filled($this->label) ? $this->label : $this->provider()->label;
    }

    /**
     * Where requests actually go. The workspace's own URL for a custom
     * endpoint, the catalogue's otherwise.
     */
    public function endpoint(): ?string
    {
        return filled($this->base_url) ? rtrim($this->base_url, '/') : $this->provider()->baseUrl;
    }

    public function hasKey(): bool
    {
        return filled($this->api_key);
    }

    /** "…4f2A" — enough to recognise, useless to anyone who obtains it. */
    public function maskedKey(): ?string
    {
        return $this->key_last_four === null ? null : '…'.$this->key_last_four;
    }

    /**
     * Whether the last check against the provider succeeded.
     *
     * Not a claim that the key works *now*: one that verified on Tuesday can
     * be revoked on Wednesday, and the manager finds that out the way it finds
     * out about any other failure — by falling back.
     */
    public function lastCheckPassed(): bool
    {
        return $this->verified_at !== null && $this->verification_error === null;
    }

    /**
     * Rates for this credential's model, in USD per million tokens.
     *
     * The workspace's own figures win; the catalogue fills in where it has
     * them. Null all the way down means nobody knows what this model costs, so
     * spend is reported as untracked rather than as zero.
     *
     * @return array{input: float, output: float}|null
     */
    public function rates(): ?array
    {
        if ($this->input_rate_per_mtok !== null && $this->output_rate_per_mtok !== null) {
            return [
                'input' => (float) $this->input_rate_per_mtok,
                'output' => (float) $this->output_rate_per_mtok,
            ];
        }

        return $this->provider()->rates($this->model);
    }

    public function tracksSpend(): bool
    {
        return $this->rates() !== null;
    }

    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
