<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineServerEnrollmentToken extends Model
{
    public const TYPE_ENROLLMENT = 'enrollment';

    public const TYPE_REAUTHORIZATION = 'reauthorization';

    protected $fillable = [
        'token_type',
        'device_label',
        'token_hash',
        'created_by',
        'expires_at',
        'authorization_expires_at',
        'used_at',
        'used_by_server_id',
        'target_offline_server_id',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'authorization_expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(OfflineServer::class, 'used_by_server_id');
    }

    public function targetServer(): BelongsTo
    {
        return $this->belongsTo(OfflineServer::class, 'target_offline_server_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isUsable(): bool
    {
        return ! $this->isUsed() && ! $this->isExpired();
    }

    public function isReauthorization(): bool
    {
        return $this->token_type === self::TYPE_REAUTHORIZATION;
    }
}
