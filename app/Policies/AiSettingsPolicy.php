<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Only the owner touches billing credentials.
 *
 * Staff can use every AI feature and read the usage page — they just cannot
 * see, set or remove the key that pays for it. That is the same line a payment
 * method sits on.
 */
final class AiSettingsPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function manage(User $user): bool
    {
        return $user->isOwner();
    }
}
