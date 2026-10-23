<?php

namespace App\Http\Requests\Concerns;

use App\Models\Beneficiary;
use App\Models\Location;
use App\Support\BeneficiaryOptions;
use Illuminate\Support\Str;

/**
 * Shared preparation/validation helpers for beneficiary registration so the
 * pre-registration duplicate modal, create and update forms stay consistent
 * (uppercase text, extension canonicalization, controlled vocabularies,
 * BARMM address resolution, legacy-value preservation).
 */
trait PreparesBeneficiaryInput
{
    /**
     * Uppercase + collapse whitespace for free-text fields.
     */
    protected function normalizeUpper(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? trim((string) $value);
        $value = mb_strtoupper(Str::ascii($value));

        return $value === '' ? null : $value;
    }

    /**
     * Uppercase without touching internal punctuation (keeps "I.E", "JR."),
     * used by the address and OTHER detail fields.
     */
    protected function normalizeUpperKeepPunctuation(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? trim((string) $value);
        $value = mb_strtoupper($value);

        return $value === '' ? null : $value;
    }

    /**
     * Extension dropdown: NONE/blank becomes null, common variants canonicalized.
     */
    protected function normalizeExtensionValue(mixed $value): ?string
    {
        $value = $this->normalizeUpper($value);

        if ($value === null || $value === BeneficiaryOptions::EXTENSION_NONE) {
            return null;
        }

        $aliases = [
            'JR' => 'JR.',
            'SR' => 'SR.',
            'II' => 'II',
            'III' => 'III',
            'IV' => 'IV',
            'JR.' => 'JR.',
            'SR.' => 'SR.',
        ];

        return $aliases[$value] ?? $value;
    }

    protected function trimOrNull(string $key): ?string
    {
        $value = $this->input($key);

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<int, string>
     */
    protected function legacyValues(string $field, ?int $beneficiaryId = null): array
    {
        $beneficiary = $beneficiaryId !== null ? Beneficiary::find($beneficiaryId) : null;
        $current = $beneficiary?->{$field};

        if (BeneficiaryOptions::isLegacy($field, $current)) {
            return [(string) $current];
        }

        return [];
    }

    protected function addressRule(bool $allowLegacy): array
    {
        return [
            'address' => ['nullable', 'string', 'max:255'],
            'location_code' => ['nullable', 'string', 'max:10'],
            'applied_address' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Resolve the posted address against the BARMM location reference data.
     *
     * Returns [formatted address, psgc code, legacy parts]. When the operator
     * picked a known barangay the reference row wins; otherwise a free-text
     * address (legacy edit) is kept as typed.
     *
     * @return array{0: ?string, 1: ?string, 2: array{barangay: ?string, city_municipality: ?string, province: ?string, district: ?string}}
     */
    protected function resolveAddress(bool $allowLegacy): array
    {
        $code = $this->input('location_code');
        $typed = $this->normalizeUpperKeepPunctuation($this->input('address') ?? $this->input('applied_address'));

        $legacy = [
            'barangay' => null,
            'city_municipality' => null,
            'province' => null,
            'district' => null,
        ];

        if ($code !== null && $code !== '') {
            $location = Location::query()->where('psgc_code', $code)->first();

            if ($location !== null) {
                return [
                    $location->full_address,
                    $location->psgc_code,
                    [
                        'barangay' => $location->barangay,
                        'city_municipality' => $location->city_municipality,
                        'province' => $location->province,
                        'district' => null,
                    ],
                ];
            }
        }

        if ($allowLegacy) {
            return [$typed, null, $legacy];
        }

        return [null, null, $legacy];
    }

    /**
     * @return array{occupation: ?string, id_type: ?string}
     */
    protected function normalizeOtherFields(): array
    {
        $occupationOther = null;
        $idTypeOther = null;

        if (strcasecmp((string) $this->input('occupation'), BeneficiaryOptions::OCCUPATION_OTHER) === 0) {
            $occupationOther = $this->normalizeUpper($this->input('occupation_other'));
        }

        if (strcasecmp((string) $this->input('id_type'), BeneficiaryOptions::ID_TYPE_OTHER) === 0) {
            $idTypeOther = $this->normalizeUpper($this->input('id_type_other'));
        }

        return ['occupation' => $occupationOther, 'id_type' => $idTypeOther];
    }

    /**
     * Enforce the controlled vocabularies, allowing an unchanged legacy value
     * on update only.
     */
    protected function validateControlledValues($validator, bool $allowLegacy, ?int $beneficiaryId = null): void
    {
        foreach (['occupation', 'id_type', 'beneficiary_signify', 'beneficiary_type', 'mode_of_payment', 'extension_name'] as $field) {
            $value = $this->input($field);

            if ($value === null || $value === '') {
                continue;
            }

            if (in_array($value, BeneficiaryOptions::values($field), true)) {
                continue;
            }

            if ($allowLegacy && in_array($value, $this->legacyValues($field, $beneficiaryId), true)) {
                continue;
            }

            $validator->errors()->add($field, 'The selected value is not in the approved list.');
        }
    }

    /**
     * OTHER/OTHERS require a detail value.
     */
    protected function validateOtherDetails($validator): void
    {
        if (strcasecmp((string) $this->input('occupation'), BeneficiaryOptions::OCCUPATION_OTHER) === 0
            && $this->normalizeUpper($this->input('occupation_other')) === null) {
            $validator->errors()->add('occupation_other', 'Please specify the occupation when OTHER is selected.');
        }

        if (strcasecmp((string) $this->input('id_type'), BeneficiaryOptions::ID_TYPE_OTHER) === 0
            && $this->normalizeUpper($this->input('id_type_other')) === null) {
            $validator->errors()->add('id_type_other', 'Please specify the ID type when OTHERS is selected.');
        }
    }

    /**
     * A typed address must either match the reference list (location_code) or,
     * on update only, be the record's existing free-text address.
     */
    protected function validateAddress($validator, bool $allowLegacy): void
    {
        [$address, $code] = $this->resolveAddress($allowLegacy);

        if ($code !== null) {
            return;
        }

        if ($address === null && $allowLegacy) {
            return; // nothing typed: keep existing address
        }

        if ($address === null && $this->input('address') === null && $this->input('applied_address') === null) {
            $validator->errors()->add('address', 'Select the address from the BARMM location list.');

            return;
        }

        if (! $allowLegacy) {
            $validator->errors()->add('address', 'Select the address from the BARMM location list.');
        }
    }
}
