<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable entry in a sync conflict's decision history. Rows are never
 * updated or deleted — a later resolution of the same entity appends a new
 * entry, preserving the complete audit trail.
 */
class SyncConflictDecision extends Model
{
    protected $fillable = [
        'conflict_uuid',
        'sync_conflict_id',
        'entity_type',
        'entity_uuid',
        'operation',
        'decision',
        'reason',
        'resolved_by',
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

    public function conflict(): BelongsTo
    {
        return $this->belongsTo(SyncConflict::class, 'sync_conflict_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
