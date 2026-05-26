<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\AvailabilityRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $staff_id
 * @property int $weekday
 * @property string $starts_at wall-clock HH:MM:SS in the staff member's zone
 * @property string $ends_at wall-clock HH:MM:SS in the staff member's zone
 * @property CarbonImmutable|null $effective_from
 * @property CarbonImmutable|null $effective_until
 * @property-read Staff $staff
 */
#[Fillable([
    'tenant_id', 'staff_id', 'weekday', 'starts_at', 'ends_at',
    'effective_from', 'effective_until',
])]
class AvailabilityRule extends Model
{
    /** @use HasFactory<AvailabilityRuleFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'effective_from' => 'immutable_date',
            'effective_until' => 'immutable_date',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    /**
     * Is this rule in force on the given calendar date?
     */
    public function appliesOn(CarbonImmutable $date): bool
    {
        if ($this->effective_from !== null && $date->lt($this->effective_from)) {
            return false;
        }

        if ($this->effective_until !== null && $date->gt($this->effective_until)) {
            return false;
        }

        return true;
    }

    #[Scope]
    protected function forWeekday(Builder $query, int $weekday): Builder
    {
        return $query->where('weekday', $weekday);
    }
}
