<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $keywords
 * @property int $duration_minutes
 * @property int $buffer_minutes
 * @property int $price_cents
 * @property string $color
 * @property bool $is_active
 * @property bool $requires_deposit
 * @property int $deposit_cents
 * @property int $sort_order
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Staff> $staff
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Booking> $bookings
 */
#[Fillable([
    'tenant_id', 'name', 'slug', 'description', 'keywords', 'duration_minutes', 'buffer_minutes',
    'price_cents', 'color', 'is_active', 'requires_deposit', 'deposit_cents', 'sort_order',
])]
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'buffer_minutes' => 'integer',
            'price_cents' => 'integer',
            'deposit_cents' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'requires_deposit' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Time the slot occupies in the diary: what the customer gets, plus the
     * turnaround the business needs afterwards.
     */
    public function blockingMinutes(): int
    {
        return $this->duration_minutes + $this->buffer_minutes;
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(Staff::class, 'service_staff')->withTimestamps();
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    #[Scope]
    protected function ordered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
