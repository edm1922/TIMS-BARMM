<?php

namespace App\Services\Sync;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use Illuminate\Database\Eloquent\Model;

final class SyncPayload
{
    public const BENEFICIARY_FIELDS = [
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
        'notes',
        'archived_at',
        'archive_reason',
    ];

    public const BATCH_FIELDS = [
        'name',
        'description',
        'status',
        'manager_uuid',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function for(Model $model): array
    {
        if ($model instanceof Beneficiary) {
            $payload = $model->only(self::BENEFICIARY_FIELDS);
            $payload['birthdate'] = $model->birthdate?->toDateString();
            $payload['archived_at'] = $model->archived_at?->toIso8601String();
            $payload['beneficiary_batch_uuid'] = $model->beneficiaryBatch?->global_uuid;

            return $payload;
        }

        if ($model instanceof BeneficiaryBatch) {
            return $model->only(self::BATCH_FIELDS);
        }

        return [];
    }
}
