<?php

namespace App\Services\Import;

use App\Support\BeneficiaryOptions;
use App\Support\NameNormalizer;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Parses one worksheet data row into a beneficiary payload plus messages.
 * Rules from docs/import-workflow.md §4.
 */
class RowParser
{
    /**
     * @param  array<int, Cell>  $cells  excel column index (1-based) => Cell
     * @param  array<string, int>  $fields  field name => excel column index
     * @return array{payload: array<string, mixed>, messages: list<array{severity: string, field?: string, text: string}>, valid: bool}
     */
    public function parse(array $cells, array $fields): array
    {
        $messages = [];
        $payload = [];

        $get = function (string $field) use ($cells, $fields): ?Cell {
            $col = $fields[$field] ?? null;

            return $col !== null ? ($cells[$col] ?? null) : null;
        };

        $string = function (?Cell $cell): ?string {
            if ($cell === null) {
                return null;
            }
            $value = $this->rawValue($cell);

            return $value === null ? null : mb_substr(trim((string) $value), 0, 500);
        };

        // --- names (required) ---
        $first = $string($get('first_name'));
        $middle = $string($get('middle_name'));
        $last = $string($get('last_name'));
        $extension = $string($get('extension_name'));

        foreach (['first_name' => $first, 'middle_name' => $middle, 'last_name' => $last, 'extension_name' => $extension] as $field => $value) {
            if ($value !== null && preg_match('/[\x{FFFD}]/u', $value)) {
                $messages[] = ['severity' => 'warning', 'field' => $field, 'text' => $field.' contains a replacement character � (data-entry artifact; kept as-is)'];
            }
        }

        $payload['first_name'] = $first;
        $payload['middle_name'] = $middle !== '' ? $middle : null;
        $payload['last_name'] = $last;
        $payload['extension_name'] = ($extension === null || $extension === '' || strcasecmp($extension, 'N/A') === 0)
            ? null
            : $extension;

        // --- birthdate ---
        if (isset($fields['birthdate'])) {
            $cell = $get('birthdate');
            [$iso, $raw, $issue] = $this->parseDateCell($cell);
            $payload['birthdate'] = $iso;
            $payload['birthdate_raw'] = $raw;
            if ($issue === 'missing') {
                $messages[] = ['severity' => 'warning', 'field' => 'birthdate', 'text' => 'birthdate_missing: birthdate is empty (needs review; raw value retained)'];
            } elseif ($issue === 'invalid') {
                $messages[] = ['severity' => 'warning', 'field' => 'birthdate', 'text' => 'birthdate_invalid: "'.$raw.'" is not a valid date (needs review; raw value retained)'];
            }
        }

        // --- contact ---
        if (isset($fields['contact_no'])) {
            $raw = $string($get('contact_no'));
            $payload['contact_no_raw'] = $raw;
            $payload['contact_no'] = $this->normalizeContact($raw, $messages);
        }

        // --- age (source only, never authoritative) ---
        if (isset($fields['age_source'])) {
            $value = $this->rawValue($get('age_source'));
            $payload['age_source'] = is_numeric($value) ? max(0, (int) round((float) $value)) : null;
        }

        // --- income ---
        if (isset($fields['avg_monthly_income'])) {
            $value = $this->rawValue($get('avg_monthly_income'));
            if ($value === null || trim((string) $value) === '') {
                $payload['avg_monthly_income'] = null;
            } elseif (! is_numeric($value) || (float) $value < 0) {
                $payload['avg_monthly_income'] = null;
                $messages[] = ['severity' => 'warning', 'field' => 'avg_monthly_income', 'text' => 'income_invalid: "'.mb_substr(trim((string) $value), 0, 32).'" is not a valid amount (ignored)'];
            } else {
                $payload['avg_monthly_income'] = round((float) $value, 2);
            }
        }

        // --- plain strings ---
        foreach ([
            'beneficiary_code', 'source_id_number', 'id_type', 'id_type_other',
            'source_id_no', 'epayment_account_no', 'beneficiary_type',
            'beneficiary_signify', 'mode_of_payment', 'occupation',
            'occupation_other', 'sex', 'civil_status', 'dependent_name',
            'employment_interest', 'skills_training_needed', 'address',
            'barangay', 'city_municipality', 'province', 'district',
        ] as $field) {
            if (isset($fields[$field])) {
                $value = $string($get($field));
                $payload[$field] = $value === '' ? null : mb_strtoupper($value);
            }
        }

        // --- unified address fallback from legacy location parts ---
        if (($payload['address'] ?? null) === null) {
            $parts = array_filter(
                [$payload['barangay'] ?? null, $payload['city_municipality'] ?? null, $payload['province'] ?? null, $payload['district'] ?? null],
                fn ($part) => $part !== null && trim((string) $part) !== '',
            );

            if ($parts !== []) {
                $payload['address'] = implode(', ', $parts);
            }
        }

        // --- advisory notes for values outside the controlled lists ---
        foreach ([
            'occupation' => 'occupation',
            'id_type' => 'id_type',
            'beneficiary_signify' => 'beneficiary_signify',
            'beneficiary_type' => 'beneficiary_type',
            'mode_of_payment' => 'mode_of_payment',
        ] as $field) {
            if (BeneficiaryOptions::isLegacy($field, $payload[$field] ?? null)) {
                $messages[] = [
                    'severity' => 'warning',
                    'field' => $field,
                    'text' => 'legacy_value: "'.$payload[$field].'" is not in the standard list (kept as-is; review on edit)',
                ];
            }
        }

        if (isset($fields['source_row_no'])) {
            $value = $this->rawValue($get('source_row_no'));
            $payload['source_row_no'] = is_numeric($value) ? (int) $value : null;
        }

        $valid = $payload['first_name'] !== null && $payload['first_name'] !== ''
            && $payload['last_name'] !== null && $payload['last_name'] !== '';

        if (! $valid) {
            $missingFields = [];
            if ($payload['first_name'] === null || $payload['first_name'] === '') {
                $missingFields[] = 'first_name';
            }
            if ($payload['last_name'] === null || $payload['last_name'] === '') {
                $missingFields[] = 'last_name';
            }
            $messages[] = ['severity' => 'error', 'field' => implode(',', $missingFields), 'text' => 'missing_required: first name and/or last name is empty'];
        }

        return ['payload' => $payload, 'messages' => $messages, 'valid' => $valid];
    }

    /**
     * @param  list<array{severity: string, field?: string, text: string}>  $messages
     */
    private function normalizeContact(?string $raw, array &$messages): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ((strlen($digits) === 9 || strlen($digits) === 10) && str_starts_with($digits, '9')) {
            $digits = '0'.$digits;
        }

        $length = strlen($digits);
        if ($length !== 0 && ($length < 10 || $length > 11)) {
            $messages[] = ['severity' => 'warning', 'field' => 'contact_no', 'text' => 'contact_length: "'.$raw.'" normalizes to '.$length.' digits (10–11 expected; stored with warning)'];
        }

        return $digits === '' ? null : $digits;
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} [iso, raw, issue]
     */
    private function parseDateCell(?Cell $cell): array
    {
        if ($cell === null) {
            return [null, null, 'missing'];
        }

        $value = $this->rawValue($cell);

        if ($value === null || trim((string) $value) === '') {
            return [null, null, 'missing'];
        }

        if ($value instanceof \DateTimeInterface) {
            return [$value->format('Y-m-d'), $value->format('Y-m-d'), null];
        }

        if (is_numeric($value)) {
            $serial = (float) $value;
            $isPlausibleDate = $this->hasDateFormat($cell) || ($serial >= 10000 && $serial <= 60000);

            if ($isPlausibleDate) {
                try {
                    $date = ExcelDate::excelToDateTimeObject($serial);

                    return [$date->format('Y-m-d'), $date->format('Y-m-d'), null];
                } catch (\Throwable) {
                    return [null, (string) $value, 'invalid'];
                }
            }
        }

        $text = trim((string) $value);
        $raw = mb_substr($text, 0, 32);
        $normalized = preg_replace('/[\s\'’]+$/u', '', $text) ?? $text;

        if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})(?:[ T]\d{1,2}:\d{2}(?::\d{2})?)?$/', $normalized, $m)
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return [sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]), $raw, null];
        }

        return [null, $raw, 'invalid'];
    }

    /**
     * True when the cell's number format looks like a date/time format
     * (PhpSpreadsheet 5 has no Cell::isDateTime()).
     */
    private function hasDateFormat(Cell $cell): bool
    {
        try {
            $format = (string) $cell->getStyle()->getFormatCode();
        } catch (\Throwable) {
            return false;
        }

        $stripped = preg_replace('/"[^"]*"|\[[^\]]*\]/', '', $format) ?? '';

        return preg_match('/[ymdhs]/i', $stripped) === 1;
    }

    private function rawValue(?Cell $cell): mixed
    {
        if ($cell === null) {
            return null;
        }

        try {
            return $cell->isFormula() ? $cell->getCalculatedValue() : $cell->getValue();
        } catch (\Throwable) {
            return $cell->getValue();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function nameKey(array $payload): string
    {
        return NameNormalizer::matchKey(
            $payload['first_name'] ?? null,
            $payload['middle_name'] ?? null,
            $payload['last_name'] ?? null,
        );
    }
}
