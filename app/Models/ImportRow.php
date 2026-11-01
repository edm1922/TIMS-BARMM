<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\User;
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
        'duplicate_kind',
        'candidate_ids',
        'review_action',
        'reviewed_by',
        'reviewed_at',
        'apply_error',
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
        'reviewed_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function matchedBeneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class, 'matched_beneficiary_id');
    }
}
