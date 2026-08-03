<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A workspace's own AI credentials and limits.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string|null $api_key decrypted on read; never serialised
 * @property string|null $key_last_four
 * @property CarbonImmutable|null $key_set_at
 * @property int|null $key_set_by
 * @property CarbonImmutable|null $verified_at
 * @property string|null $verification_error
 * @property string|null $model
 * @property string|null $monthly_budget_usd
 * @property-read User|null $setBy
 */
#[Table('tenant_ai_settings')]
/*
 * `api_key` is deliberately absent from Fillable. It is set through
 * App\Ai\Credentials\StoreApiKey, which verifies it first — so there is no
 * path where a request body reaches the column directly.
 */
#[Fillable(['tenant_id', 'model', 'monthly_budget_usd'])]
#[Hidden(['api_key'])]
class TenantAiSettings extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            // Encrypted at rest with APP_KEY. Rotating APP_KEY without
            // re-encrypting makes stored keys unreadable — see docs/AI.md.
            'api_key' => 'encrypted',
            'key_set_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
        ];
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'key_set_by');
    }

    public function hasKey(): bool
    {
        return filled($this->api_key);
    }

    /**
     * "sk-ant-…4f2A" — enough to recognise which key is installed, useless to
     * anyone who obtains it.
     */
    public function maskedKey(): ?string
    {
        return $this->key_last_four === null ? null : 'sk-ant-…'.$this->key_last_four;
    }

    /**
     * Whether the last check against the API succeeded.
     *
     * Deliberately not a claim that the key works *now*: a key that verified
     * on Tuesday can be revoked on Wednesday, and the manager finds that out
     * the same way it finds out about any other failure — by falling back.
     */
    public function lastCheckPassed(): bool
    {
        return $this->verified_at !== null && $this->verification_error === null;
    }
}
