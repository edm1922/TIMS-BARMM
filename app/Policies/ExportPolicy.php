<?php

namespace App\Policies;

use App\Models\User;

/**
 * Beneficiary data exports are admin-only (docs/architecture.md §8).
 * Staff may browse reports on screen but not download personal data.
 */
class ExportPolicy
{
    public function export(User $user): bool
    {
        return $user->is_active && ($user->isAdmin() || $user->isManager());
    }
}
