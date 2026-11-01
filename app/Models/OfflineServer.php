<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OfflineServer extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_REVOKED = 'revoked';

    public const AUTHORIZATION_ACTIVE = 'active';

    public const AUTHORIZATION_EXPIRED = 'expired';

    public const AUTHORIZATION_UNAUTHORIZED = 'unauthorized';

    public const AUTHORIZATION_REVOKED = 'revoked';

    public const AUTHORIZATION_SUSPENDED = 'suspended';

    protected $fillable = [
        'server_uuid',
        'name',
        'description',
        'status',
        'app_version',
        'protocol_version',
        'schema_version',
        'enrolled_at',
        'authorized_at',
        'authorization_expires_at',
        'last_seen_at',
        'last_sync_at',
        'revoked_at',
        'created_by',
        'metadata',
    ];

    protected $hidden = [
        'api_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'api_token_last_used_at' => 'datetime',
            'enrolled_at' => 'datetime',
            'authorized_at' => 'datetime',
            'authorization_expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'revoked_at' => 'datetime',
            'protocol_version' => 'integer',
            'schema_version' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'server_uuid';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function enrollmentTokens(): HasMany
    {
        return $this->hasMany(OfflineServerEnrollmentToken::class, 'used_by_server_id');
    }

    public function scopes(): HasMany
    {
        return $this->hasMany(OfflineServerScope::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(SyncChange::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(SyncSnapshot::class);
    }

    public function conflicts(): HasMany
    {
        return $this->hasMany(SyncConflict::class);
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(SyncCheckpoint::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SyncEvent::class);
    }

    /**
     * The encoder accounts reported by this installation. The central server
     * keeps this registry read-only.
     */
    public function encoders(): HasMany
    {
        return $this->hasMany(OfflineServerEncoder::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeRevoked(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REVOKED);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->revoked_at === null;
    }

    /**
     * The installation is authorized while its authorization period is open.
     * A server without an authorization period is treated as unauthorized.
     */
    public function isAuthorized(): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        if ($this->authorization_expires_at === null) {
            return false;
        }

        return $this->authorization_expires_at->isFuture();
    }

    public function authorizationExpired(): bool
    {
        if ($this->authorization_expires_at === null) {
            return true;
        }

        return ! $this->authorization_expires_at->isFuture();
    }

    /**
     * active | expired | unauthorized | revoked | suspended
     */
    public function authorizationStatus(): string
    {
        if ($this->isRevoked()) {
            return self::AUTHORIZATION_REVOKED;
        }

        if ($this->status === self::STATUS_SUSPENDED) {
            return self::AUTHORIZATION_SUSPENDED;
        }

        if ($this->authorization_expires_at === null) {
            return self::AUTHORIZATION_UNAUTHORIZED;
        }

        return $this->isAuthorized()
            ? self::AUTHORIZATION_ACTIVE
            : self::AUTHORIZATION_EXPIRED;
    }

    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED || $this->revoked_at !== null;
    }

    /**
     * Destructive operations submitted by this installation (beneficiary
     * deletions) are held for central administrator approval before they are
     * applied. Defaults to true: no offline server may silently remove a
     * central record. Administrators may relax this per installation through
     * the server's metadata.
     */
    public function deletionApprovalRequired(): bool
    {
        return (bool) data_get($this->metadata, 'deletion_approval_required', true);
    }

    /**
     * @return array<int, string>
     */
    public function scopeCodes(): array
    {
        return $this->scopes()
            ->whereNotNull('location_code')
            ->pluck('location_code')
            ->all();
    }
}
