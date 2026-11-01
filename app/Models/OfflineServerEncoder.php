<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineServerEncoder extends Model
{
    protected $table = 'offline_server_encoders';

    protected $fillable = [
        'offline_server_id',
        'encoder_uuid',
        'username',
        'name',
        'role',
        'is_active',
        'reported_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'reported_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(OfflineServer::class, 'offline_server_id');
    }
}
