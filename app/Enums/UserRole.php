<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';
    case Staff = 'staff';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Staff => 'Staff',
            self::Customer => 'Customer',
        };
    }

    /**
     * Owners administer the tenant. Staff see and manage their own diary.
     */
    public function isAdmin(): bool
    {
        return $this === self::Owner;
    }

    public function canAccessAdminArea(): bool
    {
        return $this === self::Owner || $this === self::Staff;
    }
}
