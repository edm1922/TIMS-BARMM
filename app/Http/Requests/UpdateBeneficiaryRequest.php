<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;

class UpdateBeneficiaryRequest extends StoreBeneficiaryRequest
{
    /**
     * Inherits the store rules. Generated code, source_id_number and
     * name_key are never accepted from user input (immutable).
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        unset($rules['birthdate_raw'], $rules['duplicate_check_done']);

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        // Update allows legacy values to survive: an unchanged stored value is
        // never blocked by the controlled vocabulary.
        $validator->after(function ($validator): void {
            $this->validateControlledValues($validator, allowLegacy: true, beneficiaryId: $this->route('beneficiary')?->id);
            $this->validateOtherDetails($validator);
            $this->validateAddress($validator, allowLegacy: true);
        });
    }

    public function beneficiaryPayload(): array
    {
        $payload = parent::beneficiaryPayload();

        // Address/legacy parts derived from the reference row only when chosen.
        if ($this->input('location_code') === null || $this->input('location_code') === '') {
            unset($payload['barangay'], $payload['city_municipality'], $payload['province'], $payload['district']);
            unset($payload['location_code']);
        }

        return $payload;
    }
}
