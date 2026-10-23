<?php

namespace App\Policies;

use App\Models\BeneficiaryBatch;
use App\Models\User;

class BeneficiaryBatchPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_active ? null : false;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, BeneficiaryBatch $beneficiaryBatch): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, BeneficiaryBatch $beneficiaryBatch): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, BeneficiaryBatch $beneficiaryBatch): bool
    {
        return $user->isAdmin();
    }

    public function assign(User $user): bool
    {
        return $user->isAdmin();
    }
}
