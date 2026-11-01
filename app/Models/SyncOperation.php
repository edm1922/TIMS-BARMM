<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SyncOperation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SYNCING = 'syncing';

    public const STATUS_SYNCED = 'synced';

    public const STATUS_CONFLICT = 'conflict';

    /**
     * Received by the central server, but held for administrator review
     * (possible duplicate or invalid record). It is neither accepted nor
     * rejected yet, so it must not be presented as synchronized.
     */
    public const STATUS_AWAITING_REVIEW = 'awaiting_review';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    /**
     * A queued operation that was replaced before upload (for example an
     * unsynced create/update superseded by a deletion of the same record). It
     * is kept on record with a reason and is never attempted again.
     */
    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'operation_uuid',
        'entity_type',
        'entity_uuid',
        'operation',
        'origin_server_uuid',
        'local_id',
        'base_revision',
        'payload',
        'changed_fields',
        'status',
        'attempts',
        'last_error',
        'last_error_code',
        'available_at',
        'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'changed_fields' => 'array',
            'base_revision' => 'integer',
            'local_id' => 'integer',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'uploaded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SyncOperation $operation): void {
            $operation->operation_uuid ??= (string) Str::uuid();
            $operation->available_at ??= now();
        });
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING,
            self::STATUS_FAILED,
        ])->where(function (Builder $inner): void {
            $inner->whereNull('available_at')->orWhere('available_at', '<=', now());
        });
    }

    /**
     * Rejections caused only by the former location-scope rule. Legacy rows
     * predate last_error_code, so their message is used as a fallback.
     */
    public function scopeRejectedByFormerScope(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REJECTED)
            ->where(function (Builder $inner): void {
                $inner->where('last_error_code', 'out_of_scope')
                    ->orWhere(function (Builder $legacy): void {
                        $legacy->whereNull('last_error_code')
                            ->where('last_error', 'like', '%outside this server scope%');
                    });
            });
    }

    public function markSynced(): void
    {
        $this->forceFill([
            'status' => self::STATUS_SYNCED,
            'uploaded_at' => now(),
            'last_error' => null,
            'last_error_code' => null,
        ])->save();
    }

    public function markFailed(string $message, int $retryAfterSeconds = 300): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'attempts' => $this->attempts + 1,
            'last_error' => $message,
            'available_at' => now()->addSeconds($retryAfterSeconds),
        ])->save();
    }

    /**
     * The central server received the record but is holding it for review.
     * The local record stays exactly as it is: no local data is discarded and
     * the operation is not retried until the decision arrives.
     */
    public function markAwaitingReview(string $message): void
    {
        $this->forceFill([
            'status' => self::STATUS_AWAITING_REVIEW,
            'last_error' => $message,
            'last_error_code' => 'awaiting_review',
        ])->save();
    }

    /**
     * Return a rejected operation to the upload queue without changing its
     * operation UUID, so the central server can apply it idempotently.
     */
    public function requeue(): void
    {
        $this->forceFill([
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
            'available_at' => now(),
            'uploaded_at' => null,
        ])->save();
    }
}
