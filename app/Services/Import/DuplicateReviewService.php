<?php

namespace App\Services\Import;

use App\Models\Beneficiary;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\SyncIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Administrator review of duplicate rows raised by the import dry-run.
 *
 * A duplicate row is never applied on its own: the administrator decides
 * between skipping it (already registered), updating the existing record with
 * the imported values, or approving it as a separate registration when the
 * database uniqueness rules allow it (government ID conflicts are blocked and
 * offered as update/skip instead).
 */
class DuplicateReviewService
{
    public const ACTION_SKIP = 'skip';

    public const ACTION_UPDATE = 'update_existing';

    public const ACTION_APPROVE = 'approve_new';

    /**
     * Rows awaiting or holding a duplicate decision, with the existing record
     * to compare against.
     *
     * @return list<array<string, mixed>>
     */
    public function duplicates(ImportBatch $batch): array
    {
        $rows = $batch->rows()
            ->with('reviewer')
            ->where('outcome', 'duplicate')
            ->orderBy('excel_row')
            ->get();

        $existingIds = $rows->pluck('matched_beneficiary_id')->filter()->unique()->values();

        $existing = $existingIds->isEmpty()
            ? collect()
            : Beneficiary::query()->with('beneficiaryBatch')->whereIn('id', $existingIds)->get()->keyBy('id');

        $originUuid = SyncIdentity::originUuid();

        return $rows->map(function (ImportRow $row) use ($batch, $existing, $originUuid): array {
            $matched = $existing->get($row->matched_beneficiary_id);
            $payload = $row->payload ?? [];

            $govId = (string) ($payload['source_id_no'] ?? '');

            $hardBlocked = match ($row->duplicate_kind) {
                'government_id' => $govId !== ''
                    && Beneficiary::query()
                        ->where('source_id_no', $govId)
                        ->where('origin_server_uuid', $originUuid)
                        ->exists(),
                'government_id_in_file' => true,
                default => false,
            };

            $inFileRow = null;

            if ($row->duplicate_kind === 'government_id_in_file' && $govId !== '') {
                $inFileRow = $batch->rows()
                    ->where('id', '<', $row->id)
                    ->where('excel_row', '<', $row->excel_row)
                    ->where('applied', false)
                    ->get(['id', 'excel_row', 'payload'])
                    ->first(fn (ImportRow $other): bool => (string) (($other->payload ?? [])['source_id_no'] ?? '') === $govId)?->excel_row;
            }

            return [
                'row' => $row,
                'matched' => $matched,
                'imported' => [
                    'first_name' => $payload['first_name'] ?? null,
                    'middle_name' => $payload['middle_name'] ?? null,
                    'last_name' => $payload['last_name'] ?? null,
                    'birthdate' => $payload['birthdate'] ?? null,
                    'source_id_no' => $payload['source_id_no'] ?? null,
                    'barangay' => $payload['barangay'] ?? null,
                    'city_municipality' => $payload['city_municipality'] ?? null,
                    'province' => $payload['province'] ?? null,
                ],
                'hard_blocked' => $hardBlocked,
                'in_file_row' => $inFileRow,
                'excel_row' => $row->excel_row,
                'status' => $this->rowStatus($row),
            ];
        })->values()->all();
    }

    /**
     * @return array{
     *     total: int, ready: int, potential_duplicates: int, pending: int,
     *     approved: int, skipped: int, imported: int, failed: int
     * }
     */
    public function summary(ImportBatch $batch): array
    {
        $rows = $batch->rows()->get(['id', 'outcome', 'applied', 'operator_decision', 'review_action', 'apply_error']);

        $duplicates = $rows->where('outcome', 'duplicate');
        $imported = $rows->where('applied', true)->whereIn('operator_decision', ['insert', 'update'])->count();
        $skipped = $rows->where('applied', true)->where('operator_decision', 'skip')->count();

        return [
            'total' => $rows->count(),
            'ready' => $rows->whereIn('outcome', ['new', 'update', 'review'])->count(),
            'potential_duplicates' => $duplicates->count(),
            'pending' => $duplicates->whereNull('review_action')->where('applied', false)->count(),
            'approved' => $rows->whereNotNull('review_action')->count(),
            'skipped' => $skipped,
            'imported' => $imported,
            'failed' => $rows->whereNotNull('apply_error')->count(),
        ];
    }

    /**
     * Record the administrator's decision for one duplicate row.
     *
     * @return array{ok: bool, message: string}
     */
    public function decide(ImportBatch $batch, ImportRow $row, string $action, ?User $user): array
    {
        if (! in_array($action, [self::ACTION_SKIP, self::ACTION_UPDATE, self::ACTION_APPROVE], true)) {
            return ['ok' => false, 'message' => 'Choose how to handle this duplicate.'];
        }

        if ($row->import_batch_id !== $batch->id) {
            return ['ok' => false, 'message' => 'That row does not belong to this import batch.'];
        }

        if ($batch->status !== 'previewed') {
            return ['ok' => false, 'message' => 'Duplicates can only be reviewed before the batch is applied.'];
        }

        if ($row->outcome !== 'duplicate') {
            return ['ok' => false, 'message' => 'That row is not flagged as a duplicate.'];
        }

        if ($row->applied) {
            return ['ok' => false, 'message' => 'That row has already been applied.'];
        }

        if ($action === self::ACTION_UPDATE && $row->matched_beneficiary_id === null) {
            return ['ok' => false, 'message' => 'There is no existing beneficiary to update for this row.'];
        }

        if ($action === self::ACTION_APPROVE) {
            if ($row->duplicate_kind === 'government_id_in_file') {
                return [
                    'ok' => false,
                    'message' => 'This government ID also appears earlier in the same workbook. '
                        .'Only one record can hold it — choose "Mark as already registered" for the duplicate row.',
                ];
            }

            $conflict = $this->governmentIdConflict($row);

            if ($conflict) {
                return [
                    'ok' => false,
                    'message' => 'This government ID number is already registered on this server, so it cannot be '
                        .'approved as a separate registration. Choose "Update existing record" or "Mark as already registered".',
                ];
            }
        }

        $row->forceFill([
            'review_action' => $action,
            'reviewed_by' => $user?->id,
            'reviewed_at' => now(),
        ])->save();

        Log::info('import.duplicate.reviewed', [
            'import_batch_id' => $batch->id,
            'excel_row' => $row->excel_row,
            'action' => $action,
            'reviewed_by' => $user?->id,
        ]);

        AuditLogger::log('import.duplicate_reviewed', 'import_batch', $batch->id, [
            'excel_row' => $row->excel_row,
            'action' => $action,
        ]);

        return ['ok' => true, 'message' => match ($action) {
            self::ACTION_SKIP => 'Row '.$row->excel_row.' marked as already registered; it will be skipped on apply.',
            self::ACTION_UPDATE => 'Row '.$row->excel_row.' will update the existing beneficiary record on apply.',
            default => 'Row '.$row->excel_row.' approved as a new registration.',
        }];
    }

    public function governmentIdConflict(ImportRow $row): bool
    {
        $govId = (string) (($row->payload ?? [])['source_id_no'] ?? '');

        if ($govId === '') {
            return false;
        }

        return Beneficiary::query()
            ->where('source_id_no', $govId)
            ->where('origin_server_uuid', SyncIdentity::originUuid())
            ->exists();
    }

    private function rowStatus(ImportRow $row): string
    {
        if ($row->apply_error !== null) {
            return 'failed';
        }

        if ($row->applied) {
            return match ($row->operator_decision) {
                'insert' => 'imported',
                'update' => 'imported',
                default => 'skipped',
            };
        }

        return match ($row->review_action) {
            self::ACTION_SKIP => 'approved_skip',
            self::ACTION_UPDATE => 'approved_update',
            self::ACTION_APPROVE => 'approved_new',
            default => 'pending',
        };
    }

    /**
     * Rows whose duplicate review decisions will be persisted.
     *
     * @return array<int, true>
     */
    public function resolvedRowIds(ImportBatch $batch): array
    {
        return DB::table('import_rows')
            ->where('import_batch_id', $batch->id)
            ->whereNotNull('review_action')
            ->pluck('id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();
    }
}
