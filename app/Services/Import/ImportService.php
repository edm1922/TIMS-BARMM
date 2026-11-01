<?php

namespace App\Services\Import;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Location;
use App\Services\AuditLogger;
use App\Support\SyncIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Dry-run preview and transactional apply (docs/import-workflow.md §5–§8).
 * Never merges records; duplicates are candidates for operator decision.
 */
class ImportService
{
    private const MAX_ROWS = 5000;

    private const EMPTY_ROW_GRACE = 5;

    public function __construct(private readonly RowParser $parser) {}

    /**
     * Dry-run: parse + classify the whole worksheet. Writes only import_rows.
     *
     * @return array<string, int|false>
     */
    public function preview(ImportBatch $batch): array
    {
        $mapping = $batch->mapping ?? [];
        $fields = $mapping['fields'] ?? [];
        $dataStart = (int) ($mapping['data_start'] ?? $batch->header_row + 2);

        $disk = Storage::disk('local');
        $path = $disk->exists($batch->stored_path) ? $disk->path($batch->stored_path) : $batch->stored_path;

        $spreadsheet = IOFactory::createReaderForFile($path)->load($path);

        try {
            $sheet = $spreadsheet->getSheetByName($batch->worksheet) ?? $spreadsheet->getActiveSheet();
            $parsed = $this->readRows($sheet, $fields, $dataStart);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        $existing = $this->loadExistingLookups(array_column($parsed, 'payload'), $batch);

        $rows = [];
        $seenSourceIds = [];
        $seenNames = [];
        $seenGovIds = [];
        $counts = array_fill_keys(['new', 'update', 'unchanged', 'duplicate', 'invalid', 'review'], 0);

        foreach ($parsed as $item) {
            $row = $this->classify($item, $existing, $batch, $seenSourceIds, $seenNames, $seenGovIds);
            $counts[$row['outcome']]++;
            $rows[] = $row;
        }

        DB::transaction(function () use ($batch, $rows) {
            $batch->rows()->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                ImportRow::query()->insert(array_map(function (array $row) use ($batch) {
                    foreach (['candidate_ids', 'messages', 'payload'] as $jsonField) {
                        if (is_array($row[$jsonField] ?? null)) {
                            $row[$jsonField] = json_encode($row[$jsonField]);
                        }
                    }

                    return array_merge([
                        'import_batch_id' => $batch->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ], $row);
                }, $chunk));
            }

            $batch->update([
                'rows_read' => count($rows),
                'status' => 'previewed',
            ]);
        });

        return $counts;
    }

    /**
     * Apply the confirmed batch in one transaction.
     *
     * @param  array<string, string>  $decisions  outcome => insert|update|skip|review
     * @return array{ok: bool, inserted: int, updated: int, skipped: int, flagged: int, error: ?string, excel_row: ?int}
     */
    public function apply(ImportBatch $batch, array $decisions): array
    {
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $flagged = 0;
        $failedRows = 0;
        $failureExcelRow = null;

        try {
            DB::transaction(function () use ($batch, $decisions, &$inserted, &$updated, &$skipped, &$flagged, &$failedRows, &$failureExcelRow) {
                // Slots left in the target batch; null means no limit.
                $targetBatch = $batch->beneficiary_batch_id !== null
                    ? BeneficiaryBatch::query()->find($batch->beneficiary_batch_id)
                    : null;
                $remainingSlots = $targetBatch?->remainingCapacity();

                $batch->rows()->where('applied', false)->chunkById(200, function ($rows) use ($batch, $decisions, &$inserted, &$updated, &$skipped, &$flagged, &$failedRows, &$failureExcelRow, &$remainingSlots) {
                    foreach ($rows as $row) {
                        $decision = $this->effectiveDecision($row, $decisions);

                        try {
                            // Each row runs in its own savepoint: a rejected
                            // record never rolls back its neighbours.
                            DB::transaction(function () use ($row, $batch, $decision, &$inserted, &$updated, &$skipped, &$flagged, &$remainingSlots) {
                                match ($decision) {
                                    'insert' => (function () use ($row, $batch, &$inserted, &$flagged, &$remainingSlots) {
                                        $payload = $row->payload ?? [];
                                        unset($payload['birthdate_raw'], $payload['contact_no_raw']);
                                        $payload['import_batch_id'] = $batch->id;
                                        // The profiling date chosen on the import page
                                        // drives date-based reporting, not the upload time.
                                        $payload['profiled_on'] = $batch->profiling_date?->toDateString()
                                            ?? now()->toDateString();
                                        if ($batch->beneficiary_batch_id) {
                                            $payload['beneficiary_batch_id'] = $batch->beneficiary_batch_id;
                                        }
                                        $this->resolveLocationCode($payload);

                                        // Race guard: recheck uniqueness right before
                                        // the insert instead of failing the whole batch.
                                        $this->assertGovernmentIdAvailable($payload);

                                        // Capacity guard: the target batch may not
                                        // exceed its configured limit.
                                        if ($remainingSlots !== null && $remainingSlots <= 0) {
                                            throw new RuntimeException(
                                                'batch_capacity: the target release batch has reached its beneficiary limit.',
                                            );
                                        }

                                        $beneficiary = Beneficiary::createWithCode($payload);
                                        $inserted++;

                                        if ($payload['beneficiary_batch_id'] ?? null) {
                                            if ($remainingSlots !== null) {
                                                $remainingSlots = max(0, $remainingSlots - 1);
                                            }
                                        }

                                        if ($beneficiary->duplicate_status === 'candidate') {
                                            $flagged++;
                                        }
                                    })(),
                                    'update' => (function () use ($row, $batch, &$updated, &$remainingSlots) {
                                        $payload = $row->payload ?? [];
                                        unset($payload['birthdate_raw'], $payload['contact_no_raw'], $payload['beneficiary_code']);
                                        if ($batch->beneficiary_batch_id) {
                                            $payload['beneficiary_batch_id'] = $batch->beneficiary_batch_id;
                                        }
                                        $this->resolveLocationCode($payload);

                                        $matched = Beneficiary::query()->find($row->matched_beneficiary_id);
                                        if ($matched !== null) {
                                            $movesIntoBatch = $batch->beneficiary_batch_id !== null
                                                && (int) $matched->beneficiary_batch_id !== (int) $batch->beneficiary_batch_id;

                                            if ($movesIntoBatch && $remainingSlots !== null && $remainingSlots <= 0) {
                                                throw new RuntimeException(
                                                    'batch_capacity: the target release batch has reached its beneficiary limit.',
                                                );
                                            }

                                            // Never overwrite a profiling date that is
                                            // already set; only fill a missing one.
                                            if ($matched->profiled_on === null && $batch->profiling_date !== null) {
                                                $payload['profiled_on'] = $batch->profiling_date;
                                            }
                                            $matched->update($payload);
                                            $updated++;

                                            if ($movesIntoBatch && $remainingSlots !== null) {
                                                $remainingSlots = max(0, $remainingSlots - 1);
                                            }
                                        } else {
                                            $skipped++;
                                        }
                                    })(),
                                    default => $skipped++,
                                };
                            });
                        } catch (RuntimeException $e) {
                            if (str_starts_with($e->getMessage(), 'batch_capacity:')) {
                                $failedRows++;
                                $row->forceFill([
                                    'apply_error' => 'The target release batch is full, so this row could not be added.',
                                ])->save();

                                continue;
                            }

                            if (str_starts_with($e->getMessage(), 'duplicate_government_id:')) {
                                $failedRows++;
                                $row->forceFill([
                                    'apply_error' => mb_substr(substr($e->getMessage(), strlen('duplicate_government_id: ')), 0, 250),
                                ])->save();

                                continue;
                            }

                            $failureExcelRow = $row->excel_row;
                            throw $e;
                        } catch (QueryException $e) {
                            if ($this->isGovernmentIdDuplicate($e)) {
                                $failedRows++;
                                $row->forceFill([
                                    'apply_error' => 'A beneficiary with this government ID number is already registered on this server.',
                                ])->save();

                                continue;
                            }

                            $failureExcelRow = $row->excel_row;
                            throw $e;
                        }

                        $row->update(['applied' => true, 'operator_decision' => $decision]);
                    }
                });

                $batch->update([
                    'status' => 'applied',
                    'inserted' => $inserted,
                    'updated' => $updated,
                    'skipped' => $skipped,
                    'flagged' => $flagged,
                    'failed' => 0,
                    'finished_at' => now(),
                ]);
            });
        } catch (QueryException|RuntimeException $e) {
            $batch->update([
                'status' => 'failed',
                'failed' => 1,
                'finished_at' => now(),
            ]);

            AuditLogger::log('import.failed', 'import_batch', $batch->id, [
                'excel_row' => $failureExcelRow,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            return [
                'ok' => false,
                'inserted' => 0,
                'updated' => 0,
                'skipped' => 0,
                'flagged' => 0,
                'failed_rows' => 0,
                'error' => $e->getMessage(),
                'excel_row' => $failureExcelRow,
            ];
        }

        AuditLogger::log('import.applied', 'import_batch', $batch->id, [
            'original_filename' => $batch->original_filename,
            'worksheet' => $batch->worksheet,
            'rows_read' => $batch->rows_read,
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'flagged' => $flagged,
            'failed_rows' => $failedRows,
        ]);

        return [
            'ok' => true,
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'flagged' => $flagged,
            'failed_rows' => $failedRows,
            'error' => null,
            'excel_row' => null,
        ];
    }

    /**
     * Throw when the payload's government ID already exists on this server.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertGovernmentIdAvailable(array $payload): void
    {
        $govId = (string) ($payload['source_id_no'] ?? '');

        if ($govId === '') {
            return;
        }

        $exists = Beneficiary::query()
            ->where('source_id_no', $govId)
            ->where('origin_server_uuid', SyncIdentity::originUuid())
            ->exists();

        if ($exists) {
            throw new RuntimeException(
                'duplicate_government_id: a beneficiary with this government ID number is already registered on this server.',
            );
        }
    }

    private function isGovernmentIdDuplicate(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return (string) $exception->getCode() === '23000'
            && str_contains($message, 'source_id_no');
    }

    /**
     * Insert a single import row as a new beneficiary, using the same payload
     * preparation as the batch apply (used by the "bypass leave blank" action).
     *
     * @param  array<string, mixed>  $payloadOverrides
     */
    public function insertRowAsNew(ImportBatch $batch, ImportRow $row, array $payloadOverrides = []): Beneficiary
    {
        $payload = array_merge($row->payload ?? [], $payloadOverrides);
        unset($payload['birthdate_raw'], $payload['contact_no_raw']);

        $payload['import_batch_id'] = $batch->id;
        $payload['profiled_on'] = $batch->profiling_date?->toDateString() ?? now()->toDateString();

        if ($batch->beneficiary_batch_id) {
            $payload['beneficiary_batch_id'] = $batch->beneficiary_batch_id;
        }

        $this->resolveLocationCode($payload);
        $this->assertGovernmentIdAvailable($payload);

        return Beneficiary::createWithCode($payload);
    }

    /**
     * @param  array<string, string>  $decisions
     */
    private function effectiveDecision(ImportRow $row, array $decisions): string
    {
        // Administrator review decisions are per row and override the
        // outcome-group defaults.
        $review = match ($row->review_action) {
            'approve_new' => 'insert',
            'update_existing' => 'update',
            'skip' => 'skip',
            default => null,
        };

        if ($row->outcome === 'duplicate') {
            // Unresolved government-ID conflicts are never registered.
            if ($review === null && $row->duplicate_kind === 'government_id') {
                return 'skip';
            }

            $decision = $review ?? ($decisions['duplicate'] ?? 'skip');

            if ($decision === 'update' && $row->matched_beneficiary_id === null) {
                return 'skip';
            }

            return $decision;
        }

        $decision = match ($row->outcome) {
            'new' => $decisions['new'] ?? 'insert',
            'update' => $decisions['update'] ?? 'update',
            'review' => $decisions['review'] ?? 'skip',
            default => 'skip', // unchanged, invalid, skipped
        };

        if ($row->outcome === 'update' && $row->matched_beneficiary_id === null) {
            return 'skip';
        }

        return $decision;
    }

    /**
     * @return list<array{excel_row: int, payload: array<string, mixed>, messages: list<array{severity: string, field?: string, text: string}>, valid: bool}>
     */
    private function readRows(Worksheet $sheet, array $fields, int $dataStart): array
    {
        $highestRow = $sheet->getHighestDataRow();
        $parsed = [];
        $empty = 0;

        for ($r = $dataStart; $r <= $highestRow && count($parsed) < self::MAX_ROWS; $r++) {
            $cells = [];
            $hasData = false;

            foreach ($fields as $col) {
                $cell = $sheet->getCell([$col, $r]);
                $cells[$col] = $cell;
                $value = $cell->getValue();
                if ($value !== null && trim((string) $value) !== '') {
                    $hasData = true;
                }
            }

            if (! $hasData) {
                if (++$empty >= self::EMPTY_ROW_GRACE) {
                    break;
                }

                continue;
            }

            $empty = 0;
            $result = $this->parser->parse($cells, $fields);
            $result['excel_row'] = $r;
            $parsed[] = $result;
        }

        return $parsed;
    }

    /**
     * Bulk-load existing records for matching (rules 1–4).
     *
     * @param  list<array<string, mixed>>  $payloads
     * @return array{
     *   by_code: array<string, Collection<int, Beneficiary>>,
     *   by_source_id_number: array<string, Collection<int, Beneficiary>>,
     *   by_source_id_no: array<string, Collection<int, Beneficiary>>,
     *   by_name_key: array<string, Collection<int, Beneficiary>>,
     * }
     */
    private function loadExistingLookups(array $payloads, ImportBatch $batch): array
    {
        $codes = [];
        $sourceIds = [];
        $govIds = [];
        $nameKeys = [];

        foreach ($payloads as $payload) {
            if (! empty($payload['beneficiary_code'])) {
                $codes[] = $payload['beneficiary_code'];
            }
            if (! empty($payload['source_id_number'])) {
                $sourceIds[] = $payload['source_id_number'];
            }
            if (! empty($payload['source_id_no'])) {
                $govIds[] = $payload['source_id_no'];
            }
            $nameKeys[] = RowParser::nameKey($payload);
        }

        $base = Beneficiary::query();

        return [
            'by_code' => collect(array_unique($codes))
                ->chunk(1000)
                ->flatMap(fn ($chunk) => $base->clone()->whereIn('beneficiary_code', $chunk->all())->get())
                ->groupBy('beneficiary_code')
                ->all(),
            'by_source_id_number' => $batch->use_source_id_as_key
                ? collect(array_unique($sourceIds))
                    ->chunk(1000)
                    ->flatMap(fn ($chunk) => $base->clone()->whereIn('source_id_number', $chunk->all())->get())
                    ->groupBy('source_id_number')
                    ->all()
                : [],
            'by_source_id_no' => collect(array_unique($govIds))
                ->chunk(1000)
                ->flatMap(fn ($chunk) => $base->clone()->whereIn('source_id_no', $chunk->all())->get())
                ->groupBy('source_id_no')
                ->all(),
            'by_name_key' => collect(array_unique($nameKeys))
                ->chunk(1000)
                ->flatMap(fn ($chunk) => $base->clone()->whereIn('name_key', $chunk->all())->get())
                ->groupBy('name_key')
                ->all(),
        ];
    }

    /**
     * @param  array{excel_row: int, payload: array<string, mixed>, messages: list<array{severity: string, field?: string, text: string}>, valid: bool}  $item
     * @param  array{by_code: array<string, Collection<int, Beneficiary>>, by_source_id_number: array<string, Collection<int, Beneficiary>>, by_name_key: array<string, Collection<int, Beneficiary>>}  $existing
     * @param  array<string, int>  $seenSourceIds
     * @param  array<string, int>  $seenNames
     * @param  array<string, int>  $seenGovIds
     * @return array<string, mixed>
     */
    private function classify(array $item, array $existing, ImportBatch $batch, array &$seenSourceIds, array &$seenNames, array &$seenGovIds): array
    {
        $payload = $item['payload'];
        $messages = $item['messages'];
        $nameKey = RowParser::nameKey($payload);
        $birthdate = $payload['birthdate'] ?? null;
        $hasBirthdate = $birthdate !== null && $birthdate !== '';

        $candidateIds = [];
        $matchedId = null;
        $outcome = null;
        $duplicateKind = null;

        if (! $item['valid']) {
            $outcome = 'invalid';
        } else {
            // in-file duplicates (first occurrence wins)
            $sn = $payload['source_id_number'] ?? null;
            $nameSig = $nameKey.'|'.($hasBirthdate ? $birthdate : '');

            $govId = trim((string) ($payload['source_id_no'] ?? ''));

            if ($govId !== '' && isset($seenGovIds[$govId])) {
                $outcome = 'duplicate';
                $duplicateKind = 'government_id_in_file';
                $messages[] = [
                    'severity' => 'warning',
                    'field' => 'source_id_no',
                    'text' => 'government_id_in_file: government ID "'.$govId.'" also appears in Excel row '.$seenGovIds[$govId],
                ];
            } elseif ($sn !== null && isset($seenSourceIds[$sn])) {
                $outcome = 'review';
                $messages[] = ['severity' => 'warning', 'field' => 'source_id_number', 'text' => 'in_file_duplicate: ID NUMBER also appears in Excel row '.$seenSourceIds[$sn]];
            } elseif ($hasBirthdate && isset($seenNames[$nameSig])) {
                $outcome = 'review';
                $messages[] = ['severity' => 'warning', 'field' => 'first_name,last_name,birthdate', 'text' => 'in_file_duplicate: same normalized name and birthdate as Excel row '.$seenNames[$nameSig]];
            } else {
                if ($sn !== null) {
                    $seenSourceIds[$sn] = $item['excel_row'];
                }
                if ($govId !== '') {
                    $seenGovIds[$govId] = $item['excel_row'];
                }
                $seenNames[$nameSig] = $item['excel_row'];
            }

            if ($outcome === null) {
                // priority 1: app-generated code
                $code = $payload['beneficiary_code'] ?? null;
                $matches = null;
                $matchField = null;
                if ($code !== null && $code !== '') {
                    $matches = $existing['by_code'][$code] ?? collect();
                    $matchField = 'beneficiary_code';
                }

                // priority 2: DOLE ID NUMBER
                if ($matches === null || $matches->isEmpty()) {
                    $sn = $payload['source_id_number'] ?? null;
                    if ($batch->use_source_id_as_key && $sn !== null && $sn !== '') {
                        $matches = $existing['by_source_id_number'][$sn] ?? collect();
                        $matchField = 'source_id_number';
                    }
                }

                if ($matches !== null && $matches->isNotEmpty()) {
                    if ($matches->count() > 1) {
                        $outcome = 'review';
                        $messages[] = ['severity' => 'warning', 'field' => $matchField ?? 'source_id_number', 'text' => 'multiple_matches: key matched '.$matches->count().' records'];
                        $candidateIds = $matches->pluck('id')->all();
                    } else {
                        $matched = $matches->first();
                        $matchedId = $matched->id;
                        $changed = $this->changedFields($payload, $matched);
                        if ($batch->beneficiary_batch_id !== null
                            && (int) $matched->beneficiary_batch_id !== (int) $batch->beneficiary_batch_id) {
                            $changed[] = 'beneficiary_batch_id';
                        }
                        if ($changed === []) {
                            $outcome = 'unchanged';
                        } else {
                            $outcome = 'update';
                            $messages[] = ['severity' => 'info', 'field' => implode(', ', $changed), 'text' => 'fields_changed: '.implode(', ', $changed)];
                        }
                    }
                } elseif (($govId = (string) ($payload['source_id_no'] ?? '')) !== ''
                    && ($govMatches = $existing['by_source_id_no'][$govId] ?? collect())->isNotEmpty()) {
                    // The imported government ID is already registered. Flag
                    // for administrator review instead of aborting the batch.
                    $outcome = 'duplicate';
                    $duplicateKind = 'government_id';

                    $sameOrigin = $govMatches->first(
                        fn (Beneficiary $match): bool => $match->origin_server_uuid === SyncIdentity::originUuid(),
                    );
                    $matched = $sameOrigin ?? $govMatches->first();
                    $matchedId = $matched->id;
                    $candidateIds = $govMatches->pluck('id')->all();

                    $messages[] = [
                        'severity' => 'warning',
                        'field' => 'source_id_no',
                        'text' => 'government_id_exists: "'.$govId.'" is already registered to '
                            .trim($matched->last_name.', '.$matched->first_name).' ('.$matched->beneficiary_code.')',
                    ];

                    if ($sameOrigin !== null) {
                        $messages[] = [
                            'severity' => 'warning',
                            'field' => 'source_id_no',
                            'text' => 'government_id_blocked: this ID number is unique on this server; '
                                .'choose "update existing" or "skip" (a separate new registration is not permitted).',
                        ];
                    }
                } elseif ($hasBirthdate && ($candidates = $existing['by_name_key'][$nameKey] ?? collect())->isNotEmpty()) {
                    $candidates = $candidates->filter(fn (Beneficiary $b) => $b->birthdate?->toDateString() === $birthdate);
                    if ($candidates->isNotEmpty()) {
                        $outcome = 'duplicate';
                        $duplicateKind = 'name_birthdate';
                        $candidateIds = $candidates->pluck('id')->all();
                        $messages[] = ['severity' => 'warning', 'field' => 'first_name,last_name,birthdate', 'text' => 'duplicate_name_birthdate: matches existing '.implode(', ', $candidates->pluck('beneficiary_code')->all())];
                    }
                }

                if ($outcome === null && ($weak = $existing['by_name_key'][$nameKey] ?? collect())->isNotEmpty()) {
                    $outcome = 'duplicate';
                    $duplicateKind = 'name_weak';
                    $candidateIds = $weak->pluck('id')->all();
                    $messages[] = ['severity' => 'warning', 'field' => 'first_name,last_name', 'text' => 'duplicate_name_weak: same name as existing '.implode(', ', $weak->pluck('beneficiary_code')->all()).' (advisory only)'];
                }

                if ($outcome === null) {
                    $outcome = 'new';
                }

                // birthdate problems force review (still importable by decision)
                foreach ($messages as $message) {
                    if (str_starts_with($message['text'], 'birthdate_')) {
                        $outcome = 'review';
                        break;
                    }
                }
            }
        }

        return [
            'excel_row' => $item['excel_row'],
            'outcome' => $outcome,
            'duplicate_kind' => $duplicateKind,
            'matched_beneficiary_id' => $matchedId,
            'candidate_ids' => $candidateIds === [] ? null : $candidateIds,
            'messages' => $messages === [] ? null : $messages,
            'payload' => $payload,
        ];
    }

    /**
     * Resolve a `location_code` from the unified address text when the source
     * did not provide one. Legacy location parts are left untouched.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveLocationCode(array &$payload): void
    {
        if (! empty($payload['location_code'])) {
            return;
        }

        $address = trim((string) ($payload['address'] ?? ''));

        if ($address === '') {
            return;
        }

        $legacy = array_filter(
            [$payload['barangay'] ?? null, $payload['city_municipality'] ?? null, $payload['province'] ?? null, $payload['district'] ?? null],
            fn ($part) => $part !== null && trim((string) $part) !== '',
        );

        $needles = $legacy !== [] ? [implode(', ', $legacy), $address] : [$address];

        foreach ($needles as $needle) {
            $location = Location::query()
                ->whereRaw('UPPER(full_address) = ?', [mb_strtoupper($needle)])
                ->first();

            if ($location !== null) {
                $payload['location_code'] = $location->psgc_code;

                return;
            }
        }
    }

    /**
     * Compare a payload against the matched record (age_source and
     * source_row_no excluded: Age is a TODAY() formula in the source).
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function changedFields(array $payload, Beneficiary $matched): array
    {
        $excluded = ['age_source', 'source_row_no', 'beneficiary_code', 'source_id_number', 'birthdate_raw', 'contact_no_raw'];
        $changed = [];

        foreach ($payload as $field => $value) {
            if (in_array($field, $excluded, true)) {
                continue;
            }

            $current = $matched->getAttribute($field);

            $normalize = function (mixed $v, string $field) {
                if ($v === null) {
                    return '';
                }
                if ($field === 'birthdate') {
                    return $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (string) $v;
                }
                if (is_numeric($v)) {
                    return number_format((float) $v, 2, '.', '');
                }

                return trim((string) $v);
            };

            if ($normalize($value, $field) !== $normalize($current, $field)) {
                $changed[] = $field;
            }
        }

        return $changed;
    }
}
