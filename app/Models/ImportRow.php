<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRow extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_batch_id',
        'excel_row',
        'outcome',
        'matched_beneficiary_id',
        'candidate_ids',
        'messages',
        'operator_decision',
        'applied',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'candidate_ids' => 'array',
            'messages' => 'array',
            'payload' => 'array',
            'applied' => 'boolean',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function matchedBeneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class, 'matched_beneficiary_id');
    }
}
