<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TenantAiSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The AI credential state of a workspace.
 *
 * There is no branch in this class that can return `api_key`. Masking it at
 * the point of serialisation, rather than remembering not to include it, is
 * what keeps that true after somebody adds a field in six months.
 *
 * @mixin TenantAiSettings
 */
final class AiSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'has_key' => $this->hasKey(),
            'masked_key' => $this->maskedKey(),
            'key_set_at' => $this->key_set_at?->toIso8601String(),
            'key_set_by' => $this->whenLoaded('setBy', fn () => $this->setBy?->name),

            'last_checked_at' => $this->verified_at?->toIso8601String(),
            'last_check_passed' => $this->lastCheckPassed(),
            'last_check_error' => $this->verification_error,

            'model' => $this->model,
            'monthly_budget_usd' => $this->monthly_budget_usd === null
                ? null
                : (float) $this->monthly_budget_usd,
        ];
    }
}
