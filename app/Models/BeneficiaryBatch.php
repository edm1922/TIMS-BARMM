<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An operational release/payout batch. Deliberately distinct from the Excel
 * import batches (import_batches): a batch groups beneficiaries for a round of
 * release, distribution, or verification work.
 */
class BeneficiaryBatch extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'name',
        'description',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function beneficiaries(): HasMany
    {
        return $this->hasMany(Beneficiary::class, 'beneficiary_batch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
