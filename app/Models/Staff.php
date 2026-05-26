<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\StaffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $user_id
 * @property string $name
 * @property string|null $title
 * @property string|null $bio
 * @property string|null $avatar_url
 * @property string $timezone
 * @property bool $is_active
 * @property int $sort_order
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AvailabilityRule> $availabilityRules
 * @property-read \Illuminate\Database\Eloquent\Collection<int, TimeOff> $timeOff
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Service> $services
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Booking> $bookings
 */
#[Table('staff')]
#[Fillable([
    'tenant_id', 'user_id', 'name', 'title', 'bio', 'avatar_url',
    'timezone', 'is_active', 'sort_order',
])]
class Staff extends Model
{
    /** @use HasFactory<StaffFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'service_staff')->withTimestamps();
    }

    public function availabilityRules(): HasMany
    {
        return $this->hasMany(AvailabilityRule::class);
    }

    public function timeOff(): HasMany
    {
        return $this->hasMany(TimeOff::class);
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
