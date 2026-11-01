<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One recorded review decision. The table is append-only: a decision is
 * evidence of who approved or rejected a record, when, and why, so it must
 * never be rewritten. A resubmission of the same record adds a new decision
 * row while the earlier one stays intact.
 */
class SyncRequestDecision extends Model
{
    public const DECISION_ACCEPTED = 'accepted';

    public const DECISION_REJECTED = 'rejected';

    protected $fillable = [
        'sync_request_id',
        'sync_request_item_id',
        'request_uuid',
        'operation_uuid',
        'entity_type',
        'entity_uuid',
        'source_server_uuid',
        'decision',
        'action',
        'reason',
        'decided_by',
        'decided_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('Review decisions are immutable; record a new decision instead.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Review decisions are immutable and cannot be deleted.');
        });
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(SyncRequest::class, 'sync_request_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(SyncRequestItem::class, 'sync_request_item_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function displayDecision(): string
    {
        return match ($this->decision) {
            self::DECISION_ACCEPTED => 'Accepted',
            self::DECISION_REJECTED => 'Rejected',
            default => ucfirst($this->decision),
        };
    }
}
