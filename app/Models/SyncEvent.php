<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SyncEvent extends Model
{
    protected $fillable = [
        'event_uuid',
        'offline_server_id',
        'type',
        'severity',
        'message',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SyncEvent $event): void {
            if (! $event->event_uuid) {
                $event->event_uuid = (string) Str::uuid();
            }
        });
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(OfflineServer::class, 'offline_server_id');
    }
}
