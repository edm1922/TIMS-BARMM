<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\PreparesBeneficiaryInput;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreBeneficiaryRequest extends FormRequest
{
    use PreparesBeneficiaryInput;

    public function authorize(): bool
    {
        return $this->user()?->is_active === true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'extension_name' => ['nullable', 'string', 'max:50'],

            'duplicate_check_done' => ['accepted'],

            'birthdate' => ['required', 'date', 'before_or_equal:today'],
            'birthdate_raw' => ['nullable', 'string', 'max:32'],
            'age_source' => ['nullable', 'integer', 'min:0', 'max:150'],

            'id_type' => ['required', 'string', 'max:100'],
            'id_type_other' => ['nullable', 'string', 'max:100'],
            'source_id_no' => ['required', 'string', 'max:64'],
            'cos_no' => ['nullable', 'string', 'max:100'],
            'contact_no' => ['required', 'string', 'max:20'],

            'beneficiary_type' => ['required', 'string', 'max:100'],
            'beneficiary_signify' => ['required', 'string', 'max:100'],
            'mode_of_payment' => ['required', 'string', 'max:100'],
            'occupation' => ['required', 'string', 'max:100'],
            'occupation_other' => ['nullable', 'string', 'max:150'],

            'sex' => ['required', 'in:MALE,FEMALE'],
            'civil_status' => ['required', 'in:SINGLE,MARRIED,WIDOW,WIDOWED,SEPARATED,DIVORCED'],
            'avg_monthly_income' => ['required', 'numeric', 'min:0'],
            'dependent_name' => ['required', 'string', 'max:150'],
            'dependent_contact_no' => ['required', 'string', 'max:20'],
            'skills_training_needed' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ] + $this->addressRule(allowLegacy: false);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'birthdate.required' => 'Birthdate is required.',
            'birthdate.before_or_equal' => 'Birthdate cannot be in the future.',
            'sex.required' => 'Select a sex.',
            'civil_status.required' => 'Select a civil status.',
            'id_type.required' => 'Select an ID type.',
            'source_id_no.required' => 'Government ID No. is required.',
            'contact_no.required' => 'Contact number is required.',
            'beneficiary_type.required' => 'Select a beneficiary type.',
            'beneficiary_signify.required' => 'Select a beneficiary signify value.',
            'occupation.required' => 'Select an occupation.',
            'mode_of_payment.required' => 'Select a mode of payment.',
            'avg_monthly_income.required' => 'Average monthly income is required.',
            'dependent_name.required' => 'Dependent name(s) is required.',
            'dependent_contact_no.required' => "Dependent's contact number is required.",
            'occupation.in' => 'Select an occupation from the list (choose OTHER to type a value).',
            'id_type.in' => 'Select an ID type from the list (choose OTHERS to type a value).',
            'beneficiary_signify.in' => 'Select a beneficiary signify value from the list.',
            'beneficiary_type.in' => 'Select a beneficiary type from the list.',
            'mode_of_payment.in' => 'Select a mode of payment from the list.',
            'extension_name.in' => 'Select a valid extension.',
            'address.required' => 'Select the beneficiary address from the BARMM location list.',
            'duplicate_check_done.accepted' => 'Run the duplicate check before registering a beneficiary.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator): void {
            $this->validateControlledValues($validator, allowLegacy: false);
            $this->validateOtherDetails($validator);
            $this->validateAddress($validator, allowLegacy: false);
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'first_name' => $this->normalizeUpper($this->input('first_name')),
            'middle_name' => $this->normalizeUpper($this->input('middle_name')),
            'last_name' => $this->normalizeUpper($this->input('last_name')),
            'extension_name' => $this->normalizeExtensionValue($this->input('extension_name')),
            'source_id_no' => $this->trimOrNull('source_id_no'),
            'cos_no' => $this->normalizeUpper($this->input('cos_no')),
            'contact_no' => $this->trimOrNull('contact_no'),
            'civil_status' => $this->normalizeUpper($this->input('civil_status')),
            'sex' => $this->normalizeUpper($this->input('sex')),
            'occupation' => $this->normalizeUpper($this->input('occupation')),
            'id_type' => $this->normalizeUpper($this->input('id_type')),
            'beneficiary_type' => $this->normalizeUpper($this->input('beneficiary_type')),
            'beneficiary_signify' => $this->normalizeUpper($this->input('beneficiary_signify')),
            'mode_of_payment' => $this->normalizeUpper($this->input('mode_of_payment')),
            'dependent_name' => $this->normalizeUpper($this->input('dependent_name')),
            'dependent_contact_no' => $this->trimOrNull('dependent_contact_no'),
            'skills_training_needed' => $this->normalizeUpper($this->input('skills_training_needed')),
        ]);
    }

    /**
     * Validated payload with derived address parts and OTHER detail applied.
     *
     * @return array<string, mixed>
     */
    public function beneficiaryPayload(): array
    {
        $payload = $this->validated();

        unset($payload['applied_address'], $payload['duplicate_check_done']);

        [$address, $code, $legacy] = $this->resolveAddress(allowLegacy: false);
        [$occupationOther, $idTypeOther] = array_values($this->normalizeOtherFields());

        $payload['address'] = $address;
        $payload['location_code'] = $code;
        $payload['barangay'] = $legacy['barangay'];
        $payload['city_municipality'] = $legacy['city_municipality'];
        $payload['province'] = $legacy['province'];
        $payload['district'] = $legacy['district'];
        $payload['occupation_other'] = $occupationOther;
        $payload['id_type_other'] = $idTypeOther;

        return $payload;
    }
}
