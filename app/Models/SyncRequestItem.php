<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One submitted operation inside a {@see SyncRequest}. The status separates
 * receipt from review from the final outcome:
 *
 *   submitted      — received, not evaluated yet
 *   applied        — clean record, applied on receipt (no blocking issue)
 *   awaiting_review— duplicate / conflict / invalid; held for a decision
 *   accepted       — an administrator approved the incoming record
 *   rejected       — an administrator (or validation) refused it, with a reason
 *   failed         — the application itself errored
 */
class SyncRequestItem extends Model
{
    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_AWAITING_REVIEW = 'awaiting_review';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'sync_request_id',
        'operation_uuid',
        'entity_type',
        'entity_uuid',
        'operation',
        'base_revision',
        'payload',
        'status',
        'failure_code',
        'duplicate_rule',
        'duplicate_matches',
        'conflict_uuid',
        'result',
        'decided_by',
        'decided_at',
        'decision_reason',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'duplicate_matches' => 'array',
            'base_revision' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(SyncRequest::class, 'sync_request_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(SyncRequestDecision::class);
    }

    public function conflict(): BelongsTo
    {
        return $this->belongsTo(SyncConflict::class, 'conflict_uuid', 'conflict_uuid');
    }

    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_AWAITING_REVIEW);
    }

    public function isAwaitingReview(): bool
    {
        return $this->status === self::STATUS_AWAITING_REVIEW;
    }

    public function isDecided(): bool
    {
        return in_array($this->status, [self::STATUS_ACCEPTED, self::STATUS_REJECTED], true);
    }

    public function displayStatus(): string
    {
        return match ($this->status) {
            self::STATUS_SUBMITTED => 'Submitted',
            self::STATUS_APPLIED => 'Applied',
            self::STATUS_AWAITING_REVIEW => 'Awaiting review',
            self::STATUS_ACCEPTED => 'Accepted',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_FAILED => 'Failed',
            default => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }
}
