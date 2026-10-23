<?php

namespace App\Policies;

use App\Models\ImportBatch;
use App\Models\User;

class ImportBatchPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_active ? null : false;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ImportBatch $importBatch): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function apply(User $user, ImportBatch $importBatch): bool
    {
        return $user->isAdmin();
    }

    public function cancel(User $user, ImportBatch $importBatch): bool
    {
        return $user->isAdmin();
    }
}
