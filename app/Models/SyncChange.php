<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SyncChange extends Model
{
    protected $fillable = [
        'change_uuid',
        'offline_server_id',
        'entity_type',
        'entity_uuid',
        'operation',
        'origin_server_uuid',
        'local_id',
        'revision',
        'payload',
        'metadata',
        'applied_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'metadata' => 'array',
            'applied_at' => 'datetime',
            'local_id' => 'integer',
            'revision' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SyncChange $change): void {
            if (! $change->change_uuid) {
                $change->change_uuid = (string) Str::uuid();
            }
        });
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(OfflineServer::class, 'offline_server_id');
    }
}
