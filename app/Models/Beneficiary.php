<?php

namespace App\Models;

use App\Services\AgeService;
use App\Services\BeneficiaryIdGenerator;
use App\Services\DuplicateDetector;
use App\Support\BeneficiaryOptions;
use App\Support\NameNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class Beneficiary extends Model
{
    use HasFactory;

    protected $fillable = [
        'beneficiary_code',
        'source_id_number',
        'source_row_no',
        'first_name',
        'middle_name',
        'last_name',
        'extension_name',
        'birthdate',
        'birthdate_raw',
        'barangay',
        'city_municipality',
        'province',
        'district',
        'address',
        'location_code',
        'id_type',
        'id_type_other',
        'source_id_no',
        'cos_no',
        'contact_no',
        'contact_no_raw',
        'epayment_account_no',
        'beneficiary_type',
        'beneficiary_signify',
        'mode_of_payment',
        'occupation',
        'occupation_other',
        'sex',
        'civil_status',
        'age_source',
        'avg_monthly_income',
        'dependent_name',
        'dependent_contact_no',
        'employment_interest',
        'skills_training_needed',
        'photo_path',
        'signature_path',
        'duplicate_status',
        'duplicate_note',
        'import_batch_id',
        'beneficiary_batch_id',
        'archived_at',
        'archive_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'archived_at' => 'datetime',
            'avg_monthly_income' => 'decimal:2',
            'age_source' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Beneficiary $beneficiary): void {
            if (! $beneficiary->beneficiary_code) {
                $beneficiary->beneficiary_code = app(BeneficiaryIdGenerator::class)
                    ->generate($beneficiary->first_name, $beneficiary->middle_name, $beneficiary->last_name);
            }

            // Flag possible duplicates on every creation path (manual entry,
            // import apply). Candidates are reported — never merged.
            if (($beneficiary->duplicate_status ?? 'none') === 'none') {
                $candidates = app(DuplicateDetector::class)->find(
                    null,
                    $beneficiary->source_id_number,
                    $beneficiary->source_id_no,
                    $beneficiary->first_name,
                    $beneficiary->middle_name,
                    $beneficiary->last_name,
                    $beneficiary->birthdate?->toDateString(),
                );

                if ($candidates !== null) {
                    $beneficiary->duplicate_status = 'candidate';
                }
            }
        });

        static::saving(function (Beneficiary $beneficiary): void {
            $beneficiary->name_key = NameNormalizer::matchKey(
                $beneficiary->first_name,
                $beneficiary->middle_name,
                $beneficiary->last_name,
            );
        });
    }

    /**
     * Create a beneficiary, retrying safely on beneficiary_code collisions.
     * The unique constraint remains the source of truth.
     */
    public static function createWithCode(array $attributes, int $attempts = 5): static
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return static::create($attributes);
            } catch (QueryException $e) {
                $isCodeCollision = (string) $e->getCode() === '23000'
                    && str_contains(strtolower($e->getMessage()), 'beneficiary_code');

                if (! $isCodeCollision || $attempt >= $attempts) {
                    throw $e;
                }

                $failed = $attributes['beneficiary_code'] ?? null;
                $generator = app(BeneficiaryIdGenerator::class);

                $next = $generator->generate(
                    $attributes['first_name'] ?? '',
                    $attributes['middle_name'] ?? null,
                    $attributes['last_name'] ?? '',
                );

                // stale snapshot: the freshly computed candidate still collides,
                // so widen the suffix range for this attempt
                if ($next === $failed) {
                    $next = $generator->generate(
                        $attributes['first_name'] ?? '',
                        $attributes['middle_name'] ?? null,
                        $attributes['last_name'] ?? '',
                        $attempt + 1,
                    );
                }

                $attributes['beneficiary_code'] = $next;
            }
        }
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    /**
     * Members of an operational release/payout batch. A null id matches every
     * beneficiary that is not assigned to any batch.
     */
    public function scopeInBatch(Builder $query, ?int $batchId): Builder
    {
        if ($batchId === null) {
            return $query->whereNull('beneficiary_batch_id');
        }

        return $query->where('beneficiary_batch_id', $batchId);
    }

    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull('beneficiary_batch_id');
    }

    /**
     * Search beneficiaries across codes, names (including full name),
     * location, and occupation. Supports multi-token and space/hyphen queries.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        $like = '%'.$term.'%';
        $hyphenated = '%'.str_replace(' ', '-', $term).'%';
        $tokens = array_values(array_filter(preg_split('/\s+/', $term) ?: []));

        return $query->where(function (Builder $q) use ($tokens, $like, $hyphenated) {
            $q->where('beneficiary_code', 'like', $like)
                ->orWhere('beneficiary_code', 'like', $hyphenated)
                ->orWhere('source_id_number', 'like', $like)
                ->orWhere('source_id_no', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('middle_name', 'like', $like)
                ->orWhere('address', 'like', $like)
                ->orWhere('barangay', 'like', $like)
                ->orWhere('city_municipality', 'like', $like)
                ->orWhere('occupation', 'like', $like);

            if (DB::getDriverName() === 'sqlite') {
                $q->orWhereRaw("(first_name || ' ' || COALESCE(middle_name, '') || ' ' || last_name) LIKE ?", [$like])
                    ->orWhereRaw("(first_name || ' ' || last_name) LIKE ?", [$like])
                    ->orWhereRaw("(last_name || ' ' || first_name) LIKE ?", [$like]);
            } else {
                $q->orWhereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ?", [$like])
                    ->orWhereRaw("CONCAT_WS(' ', first_name, last_name) LIKE ?", [$like])
                    ->orWhereRaw("CONCAT_WS(' ', last_name, first_name) LIKE ?", [$like])
                    ->orWhereRaw("CONCAT_WS(', ', last_name, first_name) LIKE ?", [$like]);
            }

            if (count($tokens) > 1) {
                $q->orWhere(function (Builder $tokenSub) use ($tokens) {
                    foreach ($tokens as $token) {
                        $tokenLike = '%'.$token.'%';
                        $tokenSub->where(function (Builder $w) use ($tokenLike) {
                            $w->where('first_name', 'like', $tokenLike)
                                ->orWhere('middle_name', 'like', $tokenLike)
                                ->orWhere('last_name', 'like', $tokenLike)
                                ->orWhere('beneficiary_code', 'like', $tokenLike)
                                ->orWhere('source_id_number', 'like', $tokenLike)
                                ->orWhere('source_id_no', 'like', $tokenLike)
                                ->orWhere('barangay', 'like', $tokenLike)
                                ->orWhere('city_municipality', 'like', $tokenLike)
                                ->orWhere('address', 'like', $tokenLike)
                                ->orWhere('occupation', 'like', $tokenLike);
                        });
                    }
                });
            }
        });
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * The operational release/payout batch this beneficiary belongs to.
     * Distinct from the Excel import batch (import_batch_id).
     */
    public function beneficiaryBatch(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryBatch::class, 'beneficiary_batch_id');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function archive(?string $reason = null): void
    {
        $this->forceFill(['archived_at' => now(), 'archive_reason' => $reason])->save();
    }

    public function unarchive(): void
    {
        $this->forceFill(['archived_at' => null, 'archive_reason' => null])->save();
    }

    /**
     * Age in whole years calculated from the birthdate (never from age_source).
     */
    public function computedAge(): ?int
    {
        return AgeService::age($this->birthdate);
    }

    /**
     * adult | underage | unknown
     */
    public function ageStatus(): string
    {
        return AgeService::status($this->birthdate);
    }

    public function getFullNameAttribute(): string
    {
        $parts = array_filter([
            trim($this->first_name.' '.$this->middle_name),
            $this->last_name,
        ]);

        $name = trim(implode(' ', $parts));

        if ($this->extension_name) {
            $name .= ' '.$this->extension_name;
        }

        return $name;
    }

    /**
     * Unified BARMM address. Falls back to the legacy address parts for
     * records captured before the location reference data existed.
     */
    public function getDisplayAddressAttribute(): string
    {
        if ($this->address !== null && trim((string) $this->address) !== '') {
            return $this->address;
        }

        return $this->card_address;
    }

    /**
     * Occupation as displayed: OTHER detail appended when present.
     */
    public function getOccupationLabelAttribute(): ?string
    {
        if ($this->occupation === null) {
            return null;
        }

        if (strcasecmp($this->occupation, BeneficiaryOptions::OCCUPATION_OTHER) === 0 && $this->occupation_other) {
            return $this->occupation.' — '.$this->occupation_other;
        }

        return $this->occupation;
    }

    /**
     * ID type as displayed: OTHERS detail appended when present.
     */
    public function getIdTypeLabelAttribute(): ?string
    {
        if ($this->id_type === null) {
            return null;
        }

        if (strcasecmp($this->id_type, BeneficiaryOptions::ID_TYPE_OTHER) === 0 && $this->id_type_other) {
            return $this->id_type.' — '.$this->id_type_other;
        }

        return $this->id_type;
    }

    /**
     * Address printed on the ID card back: the unified address when present,
     * otherwise Barangay, City/Municipality, Province, District (blanks
     * omitted) — docs/id-card-layout.md §4.
     */
    public function getCardAddressAttribute(): string
    {
        if ($this->address !== null && trim((string) $this->address) !== '') {
            return $this->address;
        }

        return implode(', ', array_filter(
            [$this->barangay, $this->city_municipality, $this->province, $this->district],
            fn ($part) => $part !== null && trim((string) $part) !== '',
        ));
    }
}
