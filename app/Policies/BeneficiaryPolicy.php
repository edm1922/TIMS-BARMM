<?php

namespace App\Policies;

use App\Models\Beneficiary;
use App\Models\User;

class BeneficiaryPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_active ? null : false;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Beneficiary $beneficiary): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Beneficiary $beneficiary): bool
    {
        return true;
    }

    public function archive(User $user, Beneficiary $beneficiary): bool
    {
        return $user->isAdmin();
    }

    public function unarchive(User $user, Beneficiary $beneficiary): bool
    {
        return $user->isAdmin();
    }

    public function resolveDuplicate(User $user, Beneficiary $beneficiary): bool
    {
        return $user->isAdmin();
    }
}
