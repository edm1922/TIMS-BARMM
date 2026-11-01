<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An administrator-added value for a controlled beneficiary vocabulary field
 * (Type of ID, Occupation, …). Merged into BeneficiaryOptions at runtime.
 */
class BeneficiaryOptionValue extends Model
{
    protected $fillable = [
        'field',
        'value',
        'normalized',
        'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Case/whitespace-insensitive comparison key for duplicate prevention.
     */
    public static function normalize(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?? $value);
    }
}
