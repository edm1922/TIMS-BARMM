<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncSnapshot extends Model
{
    protected $fillable = [
        'snapshot_uuid',
        'offline_server_id',
        'status',
        'revision_from',
        'revision_to',
        'manifest',
        'row_count',
        'expires_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'manifest' => 'array',
            'revision_from' => 'integer',
            'revision_to' => 'integer',
            'row_count' => 'integer',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(OfflineServer::class, 'offline_server_id');
    }
}
