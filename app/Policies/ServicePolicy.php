<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Service;
use App\Models\User;

final class ServicePolicy
{
    public function viewAny(?User $user): bool
    {
        // The service list is the shop window — public by design.
        return true;
    }

    public function view(?User $user, Service $service): bool
    {
        return $service->is_active || ($user?->canAccessAdminArea() ?? false);
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, Service $service): bool
    {
        return $user->isOwner() && $user->tenant_id === $service->tenant_id;
    }

    public function delete(User $user, Service $service): bool
    {
        return $this->update($user, $service);
    }
}
