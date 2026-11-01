<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $fillable = [
        'event_uuid',
        'user_id',
        'actor_uuid',
        'actor_name',
        'actor_username',
        'actor_role',
        'origin_server_uuid',
        'occurred_at',
        'synced_at',
        'action',
        'entity_type',
        'entity_id',
        'metadata',
        'ip',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
