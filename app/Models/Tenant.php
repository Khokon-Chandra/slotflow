<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One business on the platform.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $timezone
 * @property string $currency
 * @property string $locale
 * @property string|null $contact_email
 * @property string|null $phone
 * @property string|null $description
 * @property array<string, mixed>|null $settings
 */
#[Fillable([
    'name', 'slug', 'timezone', 'currency', 'locale',
    'contact_email', 'phone', 'description', 'settings',
])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * A booking rule for this tenant: its own override if it has one, the
     * platform default from config/slotflow.php otherwise.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key)
            ?? config("slotflow.{$key}", $default);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
