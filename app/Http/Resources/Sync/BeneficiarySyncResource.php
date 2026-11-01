<?php

namespace App\Http\Resources\Sync;

use App\Models\Beneficiary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Beneficiary
 */
class BeneficiarySyncResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'global_uuid' => $this->global_uuid,
            'origin_server_uuid' => $this->origin_server_uuid,
            'revision' => $this->revision,
            'beneficiary_code' => $this->beneficiary_code,
            'source_id_number' => $this->source_id_number,
            'source_row_no' => $this->source_row_no,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'extension_name' => $this->extension_name,
            'name_key' => $this->name_key,
            'birthdate' => $this->birthdate?->toDateString(),
            'birthdate_raw' => $this->birthdate_raw,
            'barangay' => $this->barangay,
            'city_municipality' => $this->city_municipality,
            'province' => $this->province,
            'district' => $this->district,
            'address' => $this->address,
            'location_code' => $this->location_code,
            'id_type' => $this->id_type,
            'id_type_other' => $this->id_type_other,
            'source_id_no' => $this->source_id_no,
            'cos_no' => $this->cos_no,
            'contact_no' => $this->contact_no,
            'contact_no_raw' => $this->contact_no_raw,
            'epayment_account_no' => $this->epayment_account_no,
            'beneficiary_type' => $this->beneficiary_type,
            'beneficiary_signify' => $this->beneficiary_signify,
            'mode_of_payment' => $this->mode_of_payment,
            'occupation' => $this->occupation,
            'occupation_other' => $this->occupation_other,
            'sex' => $this->sex,
            'civil_status' => $this->civil_status,
            'age_source' => $this->age_source,
            'avg_monthly_income' => $this->avg_monthly_income,
            'dependent_name' => $this->dependent_name,
            'dependent_contact_no' => $this->dependent_contact_no,
            'employment_interest' => $this->employment_interest,
            'skills_training_needed' => $this->skills_training_needed,
            'duplicate_status' => $this->duplicate_status,
            'duplicate_rule' => $this->duplicate_rule,
            'duplicate_note' => $this->duplicate_note,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'archive_reason' => $this->archive_reason,
            'notes' => $this->notes,
            'beneficiary_batch_uuid' => $this->whenLoaded(
                'beneficiaryBatch',
                fn () => $this->beneficiaryBatch?->global_uuid,
            ),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
