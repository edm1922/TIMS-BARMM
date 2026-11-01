<?php

namespace App\Services\Import;

/**
 * Turns the import engine's technical row messages into plain-language notes
 * for the administrator UI, e.g.
 *
 *   legacy_value: "FARMER" is not in the standard list (kept as-is; review on edit)
 *   → "FARMER" isn't one of the standard Occupation options. It is kept exactly as
 *     written in the file; review it when editing the record.
 *
 * Unknown messages are returned unchanged — the raw text is never lost, and the
 * row report (CSV) keeps the original codes for audit.
 */
final class ImportMessageText
{
    /**
     * Field labels used in friendly copy.
     *
     * @var array<string, string>
     */
    private const FIELD_LABELS = [
        'occupation' => 'Occupation',
        'id_type' => 'Type of ID',
        'beneficiary_signify' => 'Beneficiary signify',
        'beneficiary_type' => 'Type of beneficiary',
        'mode_of_payment' => 'Mode of payment',
        'source_id_number' => 'ID NUMBER',
        'source_id_no' => 'government ID',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'birthdate' => 'Birthdate',
        'avg_monthly_income' => 'Average monthly income',
    ];

    /**
     * @param  array{severity?: string, field?: string, text?: string}  $message
     */
    public static function friendly(array $message): string
    {
        $text = (string) ($message['text'] ?? '');
        $field = (string) ($message['field'] ?? '');

        if ($text === '') {
            return '';
        }

        [$code, $rest] = array_pad(explode(': ', $text, 2), 2, '');
        $rest = trim($rest);

        if ($code === 'legacy_value') {
            $value = self::quoted($rest);
            $label = self::fieldLabel($field);

            return $value === null
                ? 'A value in this row is not one of the standard options. It is kept exactly as written in the file.'
                : '"'.$value.'" isn\'t one of the standard '.$label.' options. It is kept exactly as written in the file; review it when editing the record.';
        }

        if ($code === 'birthdate_invalid') {
            $value = self::quoted($rest);

            return $value === null
                ? 'This row has a birthdate that could not be read as a date. The original value is kept and the row needs review.'
                : '"'.$value.'" isn\'t a valid date. The original value is kept as written and this row needs review.';
        }

        if ($code === 'birthdate_missing') {
            return 'This row has no birthdate. It needs review before the record is used.';
        }

        if ($code === 'in_file_duplicate') {
            if (preg_match('/in Excel row (\d+)/i', $rest, $m) === 1) {
                return 'This ID number also appears in Excel row '.$m[1].' of the same file.';
            }

            if (preg_match('/same normalized name and birthdate as Excel row (\d+)/i', $rest, $m) === 1) {
                return 'The same name and birthdate also appear in Excel row '.$m[1].' of the same file.';
            }

            return 'This row duplicates another row in the same file.';
        }

        if ($code === 'multiple_matches') {
            if (preg_match('/(\d+)/', $rest, $m) === 1) {
                return 'This row matches '.$m[1].' existing '.($m[1] === '1' ? 'record' : 'records').' by its ID number.';
            }

            return 'This row matches more than one existing record.';
        }

        if ($code === 'fields_changed') {
            $fields = array_filter(array_map('trim', explode(',', $rest)));

            if ($fields !== []) {
                $labels = array_map(fn (string $f): string => self::FIELD_LABELS[$f] ?? $f, $fields);

                return 'The file changes these fields on the existing record: '.implode(', ', $labels).'.';
            }

            return 'The file changes values on the existing record.';
        }

        if ($code === 'duplicate_name_birthdate') {
            if (preg_match('/matches existing (.+)$/i', $rest, $m) === 1) {
                return 'A person with the same name and birthdate already exists ('.$m[1].'). Review this row before importing.';
            }

            return 'A person with the same name and birthdate already exists. Review this row before importing.';
        }

        if ($code === 'duplicate_name_weak') {
            if (preg_match('/same name as existing (.+?) \(advisory only\)/i', $rest, $m) === 1) {
                return 'A person with the same name already exists ('.$m[1].') — advisory only.';
            }

            return 'A person with the same name already exists — advisory only.';
        }

        if ($code === 'income_invalid') {
            $value = self::quoted($rest);

            return $value === null
                ? 'The average monthly income could not be read as an amount and was ignored.'
                : '"'.$value.'" isn\'t a valid amount, so the income value was ignored.';
        }

        if ($code === 'missing_required') {
            return 'First name and/or last name is missing, so this row can\'t be imported.';
        }

        if (str_contains($text, 'replacement character')) {
            return 'Some text contains an encoding artifact (the value was kept as-is).';
        }

        return $text;
    }

    private static function quoted(string $text): ?string
    {
        if (preg_match('/"([^"]*)"/', $text, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private static function fieldLabel(string $field): string
    {
        if ($field === '') {
            return 'value';
        }

        return self::FIELD_LABELS[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }
}
