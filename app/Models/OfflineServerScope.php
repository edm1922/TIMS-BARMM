<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineServerScope extends Model
{
    protected $fillable = [
        'offline_server_id',
        'scope_type',
        'location_code',
        'province',
        'city_municipality',
        'barangay',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(OfflineServer::class, 'offline_server_id');
    }
}
