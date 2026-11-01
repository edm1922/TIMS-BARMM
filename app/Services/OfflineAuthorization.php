<?php

namespace App\Services;

use App\Models\SyncSetting;
use App\Support\SyncIdentity;
use Carbon\Carbon;

/**
 * Local view of the installation authorization period.
 *
 * The local record is advisory only: the central server always re-checks the
 * authorization period before accepting uploads or serving data. This class
 * exists so the offline installation can stop protected writes immediately
 * instead of discovering the expiry during the next sync attempt.
 */
class OfflineAuthorization
{
    public function enrolled(): bool
    {
        return SyncSetting::get('server_uuid') !== null
            && SyncSetting::getEncrypted('server_token') !== null;
    }

    public function authorizedAt(): ?Carbon
    {
        $value = SyncSetting::get('authorized_at');

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    public function expiresAt(): ?Carbon
    {
        $value = SyncSetting::get('authorization_expires_at');

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    /**
     * The installation is only gated when it runs in offline mode and is
     * enrolled with a central server. Online installations and fresh
     * installations are never blocked.
     */
    public function isExpired(): bool
    {
        if (! SyncIdentity::isOffline() || ! $this->enrolled()) {
            return false;
        }

        $expiresAt = $this->expiresAt();

        if ($expiresAt === null) {
            return false;
        }

        $skew = (int) config('sync.authorization_clock_skew_minutes', 5);

        return now()->greaterThan($expiresAt->copy()->addMinutes($skew));
    }

    public function isAuthorized(): bool
    {
        if (! SyncIdentity::isOffline() || ! $this->enrolled()) {
            return true;
        }

        return ! $this->isExpired();
    }

    public function preparedAt(): ?Carbon
    {
        $value = SyncSetting::get('prepared_at');

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    public function isPrepared(): bool
    {
        if (! SyncIdentity::isOffline() || ! $this->enrolled()) {
            return false;
        }

        $preparedAt = $this->preparedAt();

        if ($preparedAt === null) {
            return false;
        }

        // Prepared status expires when authorization expires
        return ! $this->isExpired();
    }

    /**
     * active | expired | not_enrolled
     */
    public function status(): string
    {
        if (! SyncIdentity::isOffline() || ! $this->enrolled()) {
            return 'not_enrolled';
        }

        return $this->isExpired() ? 'expired' : 'active';
    }
}
