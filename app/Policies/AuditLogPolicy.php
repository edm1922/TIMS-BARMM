<?php

namespace App\Policies;

use App\Models\User;

/**
 * The audit trail records who did what with sensitive records, so only
 * active administrators may read it (brief §8 "access-controlled").
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->isAdmin() || $user->isManager());
    }
}
