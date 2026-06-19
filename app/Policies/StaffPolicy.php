<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Staff;
use App\Models\User;

final class StaffPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, Staff $staff): bool
    {
        return $staff->is_active || ($user?->canAccessAdminArea() ?? false);
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, Staff $staff): bool
    {
        return $user->isOwner() && $user->tenant_id === $staff->tenant_id;
    }

    public function delete(User $user, Staff $staff): bool
    {
        return $this->update($user, $staff);
    }

    /**
     * Owners manage anyone's hours; a staff member manages their own. Nobody
     * edits a colleague's availability.
     */
    public function manageAvailability(User $user, Staff $staff): bool
    {
        if ($user->tenant_id !== $staff->tenant_id) {
            return false;
        }

        return $user->isOwner() || $staff->user_id === $user->id;
    }
}
