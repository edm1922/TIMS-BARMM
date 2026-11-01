<?php

namespace App\Services\Import;

use App\Models\Beneficiary;
use App\Models\BeneficiaryOptionValue;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Support\BeneficiaryOptions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Resolves "legacy_value" warnings raised by the import dry-run: an imported
 * value that is not part of the controlled vocabulary for its field.
 *
 * Two administrator actions are supported:
 *  - add the value to the standard options (persisted in
 *    beneficiary_option_values and merged into BeneficiaryOptions);
 *  - replace it with an existing standard option for that field, applied to
 *    every affected row (and to the already-imported beneficiary records when
 *    the batch has been applied).
 */
class InvalidOptionResolver
{
    /**
     * Unresolved unrecognized values in a batch, grouped by field + value.
     *
     * @return list<array{field: string, field_label: string, value: string, count: int, applied: bool}>
     */
    public function unresolved(ImportBatch $batch): array
    {
        $groups = [];

        /** @var Collection<int, ImportRow> $rows */
        $rows = $batch->rows()->get(['id', 'payload', 'messages', 'applied']);

        foreach ($rows as $row) {
            foreach ($row->messages ?? [] as $message) {
                $parsed = $this->parseLegacyWarning($message);

                if ($parsed === null) {
                    continue;
                }

                [$field, $value] = $parsed;
                $key = $field."\x00".$value;

                if (! isset($groups[$key])) {
                    $groups[$key] = [
                        'field' => $field,
                        'field_label' => BeneficiaryOptions::fieldLabel($field),
                        'value' => $value,
                        'count' => 0,
                        'applied' => (bool) $row->applied,
                    ];
                }

                $groups[$key]['count']++;
                $groups[$key]['applied'] = $groups[$key]['applied'] && (bool) $row->applied;
            }
        }

        return array_values($groups);
    }

    /**
     * Add the unrecognized value to the standard options for its field.
     *
     * @return array{ok: bool, message: string}
     */
    public function addOption(ImportBatch $batch, string $field, string $value, ?int $userId): array
    {
        if (! in_array($field, BeneficiaryOptions::editableFields(), true)) {
            return ['ok' => false, 'message' => 'That field does not support custom options.'];
        }

        $normalized = BeneficiaryOptionValue::normalize($value);

        // Duplicate check across both canonical and previously added values.
        foreach (BeneficiaryOptions::values($field) as $existing) {
            if (BeneficiaryOptionValue::normalize($existing) === $normalized) {
                $this->stripWarnings($batch, $field, $value);
                BeneficiaryOptions::flushExtraCache();

                return ['ok' => true, 'message' => '"'.$value.'" already exists as a standard option; the warning was cleared.'];
            }
        }

        BeneficiaryOptionValue::firstOrCreate(
            ['field' => $field, 'normalized' => $normalized],
            ['value' => $value, 'created_by' => $userId],
        );

        BeneficiaryOptions::flushExtraCache();
        $this->stripWarnings($batch, $field, $value);

        Log::info('import.invalid_option.added', [
            'import_batch_id' => $batch->id,
            'field' => $field,
            'value' => $value,
        ]);

        return ['ok' => true, 'message' => '"'.$value.'" added to the standard '.BeneficiaryOptions::fieldLabel($field).' options.'];
    }

    /**
     * Replace the unrecognized value with an existing standard option.
     *
     * @return array{ok: bool, message: string}
     */
    public function replaceOption(ImportBatch $batch, string $field, string $value, string $replacement, ?int $userId): array
    {
        if (! in_array($field, BeneficiaryOptions::editableFields(), true)) {
            return ['ok' => false, 'message' => 'That field does not support option replacement.'];
        }

        if (! in_array($replacement, BeneficiaryOptions::values($field), true)) {
            return ['ok' => false, 'message' => 'Choose an existing standard option for this field.'];
        }

        if (BeneficiaryOptionValue::normalize($replacement) === BeneficiaryOptionValue::normalize($value)) {
            return ['ok' => false, 'message' => 'The replacement must be different from the unrecognized value.'];
        }

        $updatedRows = 0;
        $updatedBeneficiaries = 0;

        DB::transaction(function () use ($batch, $field, $value, $replacement, &$updatedRows, &$updatedBeneficiaries): void {
            $rows = $batch->rows()->get(['id', 'payload', 'messages', 'applied', 'matched_beneficiary_id']);
            $matchedIds = [];
            $affected = false;

            foreach ($rows as $row) {
                $affectedRow = $this->hasLegacyWarning($row->messages ?? [], $field, $value);

                if (! $affectedRow) {
                    continue;
                }

                $affected = true;
                $payload = $row->payload ?? [];
                $payload[$field] = $replacement;

                $row->forceFill([
                    'payload' => $payload,
                    'messages' => $this->withoutLegacyWarning($row->messages ?? [], $field, $value),
                ])->save();

                $updatedRows++;

                if ($row->matched_beneficiary_id !== null) {
                    $matchedIds[] = $row->matched_beneficiary_id;
                }
            }

            if (! $affected) {
                return;
            }

            // When the batch has already been applied, fix the beneficiary
            // records created or matched by those rows.
            $query = Beneficiary::query()->where($field, $value);

            $query->where(function ($inner) use ($batch, $matchedIds): void {
                $inner->where('import_batch_id', $batch->id);

                if ($matchedIds !== []) {
                    $inner->orWhereIn('id', $matchedIds);
                }
            });

            $updatedBeneficiaries = $query->update([$field => $replacement]);
        });

        if ($updatedRows === 0) {
            return ['ok' => false, 'message' => 'No affected rows were found for that value.'];
        }

        Log::info('import.invalid_option.replaced', [
            'import_batch_id' => $batch->id,
            'field' => $field,
            'value' => $value,
            'replacement' => $replacement,
            'rows' => $updatedRows,
            'beneficiaries' => $updatedBeneficiaries,
        ]);

        return [
            'ok' => true,
            'message' => '"'.$value.'" replaced with "'.$replacement.'" for '.$updatedRows.' row(s)'
                .($updatedBeneficiaries > 0 ? ' and '.$updatedBeneficiaries.' beneficiary record(s)' : '').'.',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $message
     * @return array{0: string, 1: string}|null
     */
    private function parseLegacyWarning(array $message): ?array
    {
        if (($message['severity'] ?? '') !== 'warning') {
            return null;
        }

        $text = (string) ($message['text'] ?? '');
        $field = (string) ($message['field'] ?? '');

        if ($field === '' || ! str_starts_with($text, 'legacy_value: ')) {
            return null;
        }

        if (preg_match('/^legacy_value: "(.*)" is not in the standard list/', $text, $matches) !== 1) {
            return null;
        }

        return [$field, $matches[1]];
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function hasLegacyWarning(array $messages, string $field, string $value): bool
    {
        foreach ($messages as $message) {
            $parsed = $this->parseLegacyWarning($message);

            if ($parsed !== null && $parsed[0] === $field && $parsed[1] === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function withoutLegacyWarning(array $messages, string $field, string $value): array
    {
        return array_values(array_filter($messages, function (array $message) use ($field, $value): bool {
            $parsed = $this->parseLegacyWarning($message);

            return ! ($parsed !== null && $parsed[0] === $field && $parsed[1] === $value);
        }));
    }

    private function stripWarnings(ImportBatch $batch, string $field, string $value): void
    {
        $batch->rows()->get(['id', 'messages'])->each(function (ImportRow $row) use ($field, $value): void {
            if ($this->hasLegacyWarning($row->messages ?? [], $field, $value)) {
                $row->forceFill(['messages' => $this->withoutLegacyWarning($row->messages ?? [], $field, $value)])->save();
            }
        });
    }
}
