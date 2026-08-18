<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AiProviderCredential;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One connected provider.
 *
 * There is no branch in this class that can return `api_key`. Masking at the
 * point of serialisation, rather than remembering not to include it, is what
 * keeps that true after somebody adds a field in six months.
 *
 * @mixin AiProviderCredential
 */
final class AiProviderCredentialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'provider_label' => $this->provider()->label,
            'display_name' => $this->displayName(),
            'label' => $this->label,
            'base_url' => $this->base_url,
            'endpoint' => $this->endpoint(),

            'masked_key' => $this->maskedKey(),
            'key_set_at' => $this->key_set_at?->toIso8601String(),
            'key_set_by' => $this->whenLoaded('setBy', fn () => $this->setBy?->name),

            'model' => $this->model,
            'is_active' => $this->is_active,

            'last_checked_at' => $this->verified_at?->toIso8601String(),
            'last_check_passed' => $this->lastCheckPassed(),
            'last_check_error' => $this->verification_error,

            // Null rates mean nobody has told this application what the model
            // costs. Spend is then reported as untracked, never as zero.
            'input_rate_per_mtok' => $this->input_rate_per_mtok === null ? null : (float) $this->input_rate_per_mtok,
            'output_rate_per_mtok' => $this->output_rate_per_mtok === null ? null : (float) $this->output_rate_per_mtok,
            'tracks_spend' => $this->tracksSpend(),
        ];
    }
}
