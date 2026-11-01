<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncConflict extends Model
{
    protected $fillable = [
        'conflict_uuid',
        'offline_server_id',
        'entity_type',
        'entity_uuid',
        'operation',
        'operation_uuid',
        'origin_server_uuid',
        'local_payload',
        'remote_payload',
        'diff',
        'status',
        'resolved_by',
        'resolved_at',
        'resolution',
    ];

    protected function casts(): array
    {
        return [
            'local_payload' => 'array',
            'remote_payload' => 'array',
            'diff' => 'array',
            'resolution' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(OfflineServer::class, 'offline_server_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
