<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'beneficiary_batch_id',
        'original_filename',
        'worksheet',
        'header_row',
        'profiling_date',
        'status',
        'use_source_id_as_key',
        'mapping',
        'rows_read',
        'inserted',
        'updated',
        'skipped',
        'flagged',
        'failed',
        'started_at',
        'finished_at',
        'stored_path',
    ];

    protected function casts(): array
    {
        return [
            'profiling_date' => 'date',
            'mapping' => 'array',
            'use_source_id_as_key' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function beneficiaryBatch(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryBatch::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }
}
