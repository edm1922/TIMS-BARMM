<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single upload received from an offline server — the unit an administrator
 * reviews. Its status never uses one generic "success": receipt, review, and
 * final acceptance are distinct states.
 */
class SyncRequest extends Model
{
    /** Received by central, not evaluated yet. */
    public const STATUS_SUBMITTED = 'submitted';

    /** At least one record is waiting for an administrator decision. */
    public const STATUS_AWAITING_REVIEW = 'awaiting_review';

    /** Some records were accepted and at least one was rejected. */
    public const STATUS_PARTIALLY_ACCEPTED = 'partially_accepted';

    /** Every record was applied, either automatically or after review. */
    public const STATUS_ACCEPTED = 'accepted';

    /** Every record was rejected; nothing was applied. */
    public const STATUS_REJECTED = 'rejected';

    /** The request could not be processed at all. */
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'request_uuid',
        'offline_server_id',
        'submitted_by',
        'status',
        'total_operations',
        'applied_count',
        'accepted_count',
        'rejected_count',
        'pending_count',
        'duplicate_count',
        'conflict_count',
        'validation_error_count',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'last_activity_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'metadata' => 'array',
            'total_operations' => 'integer',
            'applied_count' => 'integer',
            'accepted_count' => 'integer',
            'rejected_count' => 'integer',
            'pending_count' => 'integer',
            'duplicate_count' => 'integer',
            'conflict_count' => 'integer',
            'validation_error_count' => 'integer',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(OfflineServer::class, 'offline_server_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SyncRequestItem::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(SyncRequestDecision::class);
    }

    /**
     * Requests that still owe an administrator a decision.
     */
    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_SUBMITTED, self::STATUS_AWAITING_REVIEW]);
    }

    public function needsReview(): bool
    {
        return in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_AWAITING_REVIEW], true);
    }

    public function displayStatus(): string
    {
        return match ($this->status) {
            self::STATUS_SUBMITTED => 'Submitted',
            self::STATUS_AWAITING_REVIEW => 'Awaiting review',
            self::STATUS_PARTIALLY_ACCEPTED => 'Partially accepted',
            self::STATUS_ACCEPTED => 'Accepted',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_FAILED => 'Failed',
            default => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }
}
