<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Staff
 */
final class StaffResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'title' => $this->title,
            'bio' => $this->bio,
            'avatar_url' => $this->avatar_url,
            'timezone' => $this->timezone,
            'is_active' => $this->is_active,
            'services' => ServiceResource::collection($this->whenLoaded('services')),
            'availability_rules' => AvailabilityRuleResource::collection($this->whenLoaded('availabilityRules')),
        ];
    }
}
