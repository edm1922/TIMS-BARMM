<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineServerUpdate extends Model
{
    protected $fillable = [
        'offline_server_id',
        'filename',
        'path',
        'size',
        'sha256',
        'files',
        'label',
        'status',
    ];

    protected $casts = [
        'size' => 'integer',
        'files' => 'integer',
    ];

    public function offlineServer(): BelongsTo
    {
        return $this->belongsTo(OfflineServer::class);
    }
}
