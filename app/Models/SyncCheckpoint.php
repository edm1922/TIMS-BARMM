<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncCheckpoint extends Model
{
    protected $fillable = [
        'offline_server_id',
        'direction',
        'last_revision',
        'cursor',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'last_revision' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(OfflineServer::class, 'offline_server_id');
    }
}
