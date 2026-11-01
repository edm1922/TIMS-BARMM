<?php

namespace App\Models;

use App\Models\Concerns\RecordsSyncChanges;
use App\Support\SyncIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * An operational release/payout batch. Deliberately distinct from the Excel
 * import batches (import_batches): a batch groups beneficiaries for a round of
 * release, distribution, or verification work.
 */
class BeneficiaryBatch extends Model
{
    use HasFactory;
    use RecordsSyncChanges;
    use SoftDeletes;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'global_uuid',
        'origin_server_uuid',
        'revision',
        'server_revision',
        'sync_status',
        'conflict_status',
        'last_operation_uuid',
        'name',
        'description',
        'status',
        'capacity',
        'beneficiary_code_series',
        'profiled_on',
        'program_year',
        'created_by',
        'manager_id',
        'manager_uuid',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
            'revision' => 'integer',
            'server_revision' => 'integer',
            'program_year' => 'integer',
            'capacity' => 'integer',
            'profiled_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (BeneficiaryBatch $batch): void {
            $batch->global_uuid ??= (string) Str::uuid();
            $batch->origin_server_uuid ??= SyncIdentity::originUuid();
            $batch->revision ??= 1;
            $batch->server_revision ??= 1;
            $batch->sync_status ??= 'synced';
            $batch->conflict_status ??= 'none';
        });
    }

    public function beneficiaries(): HasMany
    {
        return $this->hasMany(Beneficiary::class, 'beneficiary_batch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(OfflineServerEncoder::class, 'manager_id');
    }

    public function managerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_uuid', 'uuid');
    }

    public function managerUuid(): ?string
    {
        $uuid = $this->getAttribute('manager_uuid') ?: $this->manager?->encoder_uuid;

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    public function managerDisplayName(): ?string
    {
        return $this->manager?->username
            ?? $this->managerUser?->username
            ?? $this->managerUser?->name;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CLOSED);
    }

    public function scopeWithMemberCount(Builder $query): Builder
    {
        return $query->withCount('beneficiaries');
    }

    public function memberCount(): int
    {
        return (int) ($this->beneficiaries_count ?? $this->beneficiaries()->count());
    }

    /**
     * Maximum number of beneficiaries this batch may hold (null = no limit).
     */
    public function capacityLimit(): ?int
    {
        return $this->capacity !== null ? (int) $this->capacity : null;
    }

    /**
     * How many more active beneficiaries this batch can accept (null = unlimited).
     */
    public function remainingCapacity(): ?int
    {
        $limit = $this->capacityLimit();

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - $this->memberCount());
    }

    public function hasRoomFor(int $count = 1): bool
    {
        $remaining = $this->remainingCapacity();

        return $remaining === null || $remaining >= $count;
    }

    public function isFull(): bool
    {
        return $this->remainingCapacity() === 0;
    }

    /**
     * Human summary such as "12 / 100" or "12 (no limit)".
     */
    public function capacityLabel(): string
    {
        $limit = $this->capacityLimit();

        return $limit === null
            ? number_format($this->memberCount()).' (no limit)'
            : number_format($this->memberCount()).' / '.number_format($limit);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
