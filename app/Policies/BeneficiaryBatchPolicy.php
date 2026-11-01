<?php

namespace App\Policies;

use App\Models\BeneficiaryBatch;
use App\Models\User;
use App\Support\SyncIdentity;

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

    /**
     * Batches are created on the central server only. Offline installations
     * receive their batches through synchronization; letting an offline
     * manager create one would produce a batch with no centrally managed
     * identity, membership baseline, or assignment.
     */
    public function create(User $user): bool
    {
        if (SyncIdentity::isOffline()) {
            return false;
        }

        return $user->isAdmin() || $user->isManager();
    }

    public function update(User $user, BeneficiaryBatch $beneficiaryBatch): bool
    {
        $managerUuid = $beneficiaryBatch->managerUuid();

        return $user->isAdmin()
            || ($user->isManager()
                && $managerUuid !== null
                && $managerUuid === (string) $user->uuid);
    }

    public function delete(User $user, BeneficiaryBatch $beneficiaryBatch): bool
    {
        return $this->update($user, $beneficiaryBatch);
    }

    public function assign(User $user, BeneficiaryBatch $beneficiaryBatch): bool
    {
        return $this->update($user, $beneficiaryBatch);
    }

    /**
     * Registering a beneficiary is a data-entry action, distinct from editing
     * the batch itself. Staff retain their existing registration access, while
     * an encoder may register only into a batch assigned to the Manager of
     * this installation.
     */
    public function addBeneficiary(User $user, BeneficiaryBatch $beneficiaryBatch): bool
    {
        if ($user->isAdmin() || $user->isStaff()) {
            return true;
        }

        $managerUuid = $beneficiaryBatch->managerUuid();

        if ($managerUuid === null) {
            return false;
        }

        if ($user->isManager()) {
            return $managerUuid === (string) $user->uuid;
        }

        if (! $user->isEncoder()) {
            return false;
        }

        return User::query()
            ->where('uuid', $managerUuid)
            ->where('role', User::ROLE_MANAGER)
            ->where('is_active', true)
            ->exists();
    }
}
