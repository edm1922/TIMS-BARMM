<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\PreparesBeneficiaryInput;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Lightweight validation for the pre-registration duplicate check: name and
 * birthdate only (no address or controlled vocabularies required).
 */
class DuplicateCheckRequest extends FormRequest
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
            'birthdate' => ['required', 'date'],
            'extension_name' => ['nullable', 'string', 'max:50'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'first_name' => $this->normalizeUpper($this->input('first_name')),
            'middle_name' => $this->normalizeUpper($this->input('middle_name')),
            'last_name' => $this->normalizeUpper($this->input('last_name')),
            'extension_name' => $this->normalizeExtensionValue($this->input('extension_name')),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator): void {
            $this->validateControlledValues($validator, allowLegacy: false);
        });
    }
}
