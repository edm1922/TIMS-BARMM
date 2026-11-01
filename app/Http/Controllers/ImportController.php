<?php

namespace App\Http\Controllers;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Models\ImportBatch;
use App\Services\AuditLogger;
use App\Services\Export\CsvExporter;
use App\Services\Import\DuplicateReviewService;
use App\Services\Import\FieldMap;
use App\Services\Import\ImportService;
use App\Services\Import\InvalidOptionResolver;
use App\Services\Import\WorkbookInspector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    private const MAX_ROWS = 5000;

    private const MAX_SHEETS = 20;

    public function __construct(
        private readonly WorkbookInspector $inspector,
        private readonly ImportService $service,
    ) {}

    public function index(): View
    {
        Gate::authorize('viewAny', ImportBatch::class);

        $batches = ImportBatch::query()->with('user')->latest('id')->paginate(20);

        return view('imports.index', compact('batches'));
    }

    public function create(): View
    {
        Gate::authorize('create', ImportBatch::class);

        $batches = BeneficiaryBatch::query()
            ->withMemberCount()
            ->orderBy('name')
            ->get();

        return view('imports.create', compact('batches'));
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', ImportBatch::class);

        $request->validate([
            'workbook' => ['required', 'file', 'max:'.(self::MAX_BYTES / 1024)],
            'beneficiary_batch_id' => ['nullable', 'integer', 'exists:beneficiary_batches,id'],
            'profiling_date' => ['nullable', 'date'],
        ], [
            'workbook.required' => 'Choose an .xlsx workbook to upload.',
            'workbook.file' => 'The upload must be a file.',
            'workbook.max' => 'The workbook exceeds the 10 MB limit.',
            'beneficiary_batch_id.exists' => 'The selected release batch does not exist.',
            'profiling_date.date' => 'Enter a valid beneficiary profiling date.',
        ]);

        // The profiling date is independent of the upload timestamp: it is the
        // date the beneficiaries were profiled/registered and drives the
        // dashboard's registration trend.
        $profilingDate = $request->input('profiling_date') ?: now()->toDateString();

        $upload = $request->file('workbook');
        $original = $upload->getClientOriginalName();

        if (! preg_match('/\.xlsx$/i', $original)) {
            return back()->withErrors(['workbook' => 'Only .xlsx workbooks are supported (.xls/.xlsm are rejected).']);
        }

        $tmpPath = $upload->getRealPath();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath);

        if ($mime !== 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
            return back()->withErrors(['workbook' => 'The file content is not a valid .xlsx workbook (detected: '.$mime.').']);
        }

        if (filesize($tmpPath) < 4 || ! str_starts_with((string) file_get_contents($tmpPath, false, null, 0, 4), "PK\x03\x04")) {
            return back()->withErrors(['workbook' => 'The file is not a valid ZIP-based Office document.']);
        }

        $uuid = (string) Str::uuid();
        $relativePath = 'imports/'.$uuid.'.xlsx';

        Storage::disk('local')->put($relativePath, (string) file_get_contents($tmpPath));
        $absolutePath = Storage::disk('local')->path($relativePath);

        try {
            $info = $this->inspector->inspect($absolutePath);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($relativePath);

            return back()->withErrors(['workbook' => 'The workbook could not be opened (password-protected or corrupt): '.mb_substr($e->getMessage(), 0, 200)]);
        }

        if (! $info['detected']) {
            Storage::disk('local')->delete($relativePath);

            return back()->withErrors(['workbook' => 'No beneficiary header row was detected in the first 30 rows of the largest visible worksheet.']);
        }

        if (count($info['sheets']) > self::MAX_SHEETS) {
            Storage::disk('local')->delete($relativePath);

            return back()->withErrors(['workbook' => 'The workbook has more than '.self::MAX_SHEETS.' worksheets.']);
        }

        $dataRows = max(0, $info['data_end'] - $info['data_start'] + 1);
        if ($dataRows > self::MAX_ROWS) {
            Storage::disk('local')->delete($relativePath);

            return back()->withErrors(['workbook' => 'The worksheet has '.$dataRows.' data rows; the limit is '.self::MAX_ROWS.'.']);
        }

        $fields = [];
        foreach ($info['columns'] as $column) {
            if ($column['default_field'] !== '') {
                $fields[$column['default_field']] = $column['index'];
            }
        }

        $batch = ImportBatch::create([
            'user_id' => $request->user()->id,
            'beneficiary_batch_id' => $request->input('beneficiary_batch_id'),
            'original_filename' => $original,
            'worksheet' => $info['sheet'],
            'header_row' => $info['header_row'],
            'profiling_date' => $profilingDate,
            'status' => 'uploaded',
            'stored_path' => $relativePath,
            'mapping' => [
                'data_start' => $info['data_start'],
                'fields' => $fields,
                'sheets' => $info['sheets'],
                'columns' => $info['columns'],
            ],
        ]);

        AuditLogger::log('import.uploaded', 'import_batch', $batch->id, [
            'original_filename' => $original,
            'worksheet' => $info['sheet'],
            'data_rows' => $dataRows,
            'profiling_date' => $profilingDate,
        ]);

        return redirect()->route('imports.edit', $batch)
            ->with('success', 'Workbook uploaded. Confirm the worksheet and column mapping before running the dry-run.');
    }

    public function edit(ImportBatch $importBatch): View
    {
        Gate::authorize('view', $importBatch);

        if ($importBatch->status !== 'uploaded') {
            return redirect()->route('imports.show', $importBatch);
        }

        $mapping = $importBatch->mapping ?? [];

        return view('imports.edit', [
            'batch' => $importBatch,
            'columns' => $mapping['columns'] ?? [],
            'fields' => $mapping['fields'] ?? [],
            'sheets' => $mapping['sheets'] ?? [],
            'options' => FieldMap::OPTIONS,
        ]);
    }

    public function updateMapping(Request $request, ImportBatch $importBatch): RedirectResponse
    {
        Gate::authorize('view', $importBatch);

        if ($importBatch->status !== 'uploaded') {
            return redirect()->route('imports.show', $importBatch);
        }

        $mapping = $importBatch->mapping ?? [];
        $sheetNames = array_column($mapping['sheets'] ?? [], 'name');

        $data = $request->validate([
            'worksheet' => ['required', 'string'],
            'use_source_id_as_key' => ['nullable', 'boolean'],
        ]);

        // manual check: sheet names may contain commas (e.g. "1,000"), which
        // would break an "in:" validation rule
        if (! in_array($data['worksheet'], $sheetNames, true)) {
            return back()->withErrors(['worksheet' => 'The selected worksheet is not present in this workbook.'])->withInput();
        }

        $submittedFields = (array) $request->input('fields', []);
        $validTargets = array_keys(FieldMap::OPTIONS);

        $fields = [];
        foreach ($submittedFields as $col => $field) {
            $field = (string) $field;
            if ($field === '' || ! in_array($field, $validTargets, true)) {
                continue;
            }
            if (isset($fields[$field])) {
                return back()->withErrors(['fields' => 'The field "'.FieldMap::OPTIONS[$field].'" is mapped to more than one column.'])->withInput();
            }
            $fields[$field] = (int) $col;
        }

        if (! isset($fields['first_name']) || ! isset($fields['last_name'])) {
            return back()->withErrors(['fields' => 'First Name and Last Name must both be mapped.'])->withInput();
        }

        $info = $this->inspector->inspect(
            Storage::disk('local')->path($importBatch->stored_path),
            $data['worksheet'],
        );

        $mapping['fields'] = $fields;
        $mapping['data_start'] = $info['data_start'];
        $mapping['columns'] = $info['columns'];
        $mapping['sheets'] = $info['sheets'];

        $importBatch->update([
            'worksheet' => $data['worksheet'],
            'header_row' => $info['header_row'],
            'use_source_id_as_key' => $request->boolean('use_source_id_as_key'),
            'mapping' => $mapping,
        ]);

        $counts = $this->service->preview($importBatch);

        AuditLogger::log('import.previewed', 'import_batch', $importBatch->id, [
            'worksheet' => $importBatch->worksheet,
            'rows_read' => $importBatch->rows_read,
            'outcomes' => $counts,
        ]);

        return redirect()->route('imports.show', $importBatch)
            ->with('success', 'Dry-run complete: '.$importBatch->rows_read.' rows read. Nothing was written to the beneficiaries table.');
    }

    public function show(Request $request, ImportBatch $importBatch, InvalidOptionResolver $invalidOptions, DuplicateReviewService $duplicateReview): View
    {
        Gate::authorize('view', $importBatch);

        $duplicates = $duplicateReview->duplicates($importBatch);
        $duplicateSummary = $duplicateReview->summary($importBatch);

        // Failed rows carry enough context to find the record and the row in
        // the preview table: exact page, anchor, and the conflicting existing
        // beneficiary when its government ID is known.
        $allRows = $importBatch->rows()->orderBy('excel_row')->get(['id', 'excel_row', 'outcome', 'payload', 'apply_error']);

        $failedRows = $allRows->whereNotNull('apply_error')->map(function ($row) use ($importBatch, $allRows): array {
            $payload = $row->payload ?? [];
            $govId = (string) ($payload['source_id_no'] ?? '');

            $position = $allRows
                ->where('outcome', $row->outcome)
                ->values()
                ->search(fn ($candidate): bool => $candidate->id === $row->id);

            $existing = $govId === ''
                ? null
                : Beneficiary::query()->where('source_id_no', $govId)->first();

            $isGovernmentIdConflict = $govId !== '' && $existing !== null;

            return [
                'row' => $row,
                'name' => trim((string) ($payload['last_name'] ?? '').', '.($payload['first_name'] ?? '')),
                'gov_id' => $govId,
                'birthdate' => $payload['birthdate'] ?? null,
                'page' => $position === false ? 1 : intdiv((int) $position, 50) + 1,
                'anchor' => 'import-row-'.$row->id,
                'existing' => $existing,
                'view_duplicate_url' => $existing === null ? null : route('beneficiaries.show', $existing),
                'edit_url' => $existing === null ? null : route('beneficiaries.edit', $existing),
                'bypass_url' => $isGovernmentIdConflict && ! $row->applied
                    ? route('imports.rows.bypass', ['importBatch' => $importBatch, 'importRow' => $row->id])
                    : null,
            ];
        })->values();

        $outcomeFilter = $request->query('outcome', '');
        $rows = $importBatch->rows()
            ->when($outcomeFilter !== '', fn ($q) => $q->where('outcome', $outcomeFilter))
            ->orderBy('excel_row')
            ->paginate(50)
            ->withQueryString();

        $summary = $importBatch->rows()
            ->selectRaw('outcome, COUNT(*) as total')
            ->groupBy('outcome')
            ->pluck('total', 'outcome');

        $allCandidateIds = $importBatch->rows()->whereNotNull('candidate_ids')->limit(5000)->get()
            ->flatMap(fn ($row) => $row->candidate_ids ?? [])
            ->unique()
            ->all();

        $codeLookup = DB::table('beneficiaries')
            ->whereIn('id', $allCandidateIds)
            ->pluck('beneficiary_code', 'id');

        $missingFromImport = null;
        if (in_array($importBatch->status, ['previewed', 'applied'], true) && $importBatch->use_source_id_as_key) {
            $fileIds = $importBatch->rows()->get(['payload'])
                ->map(fn ($row) => $row->payload['source_id_number'] ?? null)
                ->filter()
                ->unique()
                ->values();

            if ($fileIds->isNotEmpty()) {
                $missingFromImport = Beneficiary::query()
                    ->active()
                    ->whereNotNull('source_id_number')
                    ->whereNotIn('source_id_number', $fileIds->all())
                    ->count();
            }
        }

        // Recurring validation warnings across the whole file (read-only
        // aggregation for the preview's warning summary; counts unchanged).
        $warningGroups = $importBatch->rows()
            ->whereNotNull('messages')
            ->get(['messages'])
            ->flatMap(fn ($row) => collect($row->messages ?? [])
                ->map(fn ($message) => [
                    'severity' => (string) ($message['severity'] ?? 'warning'),
                    'text' => (string) ($message['text'] ?? ''),
                ]))
            ->filter(fn ($message) => $message['text'] !== '')
            ->groupBy(fn ($message) => $message['severity'].'|'.$message['text'])
            ->map(function ($group): array {
                $first = $group->first();

                return [
                    'severity' => $first['severity'],
                    'text' => $first['text'],
                    'count' => $group->count(),
                ];
            })
            ->sortByDesc('count')
            ->take(6)
            ->values();

        return view('imports.show', [
            'batch' => $importBatch,
            'rows' => $rows,
            'summary' => $summary,
            'outcomeFilter' => $outcomeFilter,
            'codeLookup' => $codeLookup,
            'missingFromImport' => $missingFromImport,
            'warningGroups' => $warningGroups,
            'invalidOptions' => $invalidOptions->unresolved($importBatch),
            'duplicates' => $duplicates,
            'duplicateSummary' => $duplicateSummary,
            'failedRows' => $failedRows,
        ]);
    }

    public function apply(Request $request, ImportBatch $importBatch): RedirectResponse
    {
        Gate::authorize('apply', $importBatch);

        if ($importBatch->status !== 'previewed') {
            return redirect()->route('imports.show', $importBatch)
                ->with('error', 'Only batches that completed a dry-run can be applied.');
        }

        $data = $request->validate([
            'decisions' => ['required', 'array'],
            'decisions.new' => ['nullable', 'in:insert,skip'],
            'decisions.update' => ['nullable', 'in:update,skip'],
            'decisions.duplicate' => ['nullable', 'in:skip,insert'],
            'decisions.review' => ['nullable', 'in:skip,insert'],
            'confirm' => ['accepted'],
        ], [
            'confirm.accepted' => 'Confirm the batch summary before applying.',
        ]);

        $result = $this->service->apply($importBatch, array_filter($data['decisions']));

        if (! $result['ok']) {
            return redirect()->route('imports.show', $importBatch)
                ->with('error', 'Apply aborted at Excel row '.$result['excel_row'].' — the whole batch was rolled back. '.mb_substr((string) $result['error'], 0, 200));
        }

        // docs §11: the uploaded workbook is deleted immediately after apply
        if ($importBatch->stored_path && Storage::disk('local')->exists($importBatch->stored_path)) {
            Storage::disk('local')->delete($importBatch->stored_path);
        }

        $failedRows = (int) ($result['failed_rows'] ?? 0);

        return redirect()->route('imports.show', $importBatch)
            ->with('success', 'Applied: '.$result['inserted'].' inserted, '.$result['updated'].' updated, '
                .$result['skipped'].' skipped, '.$result['flagged'].' flagged as duplicate candidates.'
                .($failedRows > 0 ? ' '.$failedRows.' row(s) could not be applied and were left for follow-up.' : ''));
    }

    /**
     * Resolve an unrecognized imported option value: add it as a new standard
     * option or replace it with an existing option for that field.
     */
    public function resolveInvalidOption(Request $request, ImportBatch $importBatch, InvalidOptionResolver $invalidOptions): RedirectResponse
    {
        Gate::authorize('apply', $importBatch);

        if (! in_array($importBatch->status, ['previewed', 'applied'], true)) {
            return redirect()->route('imports.show', $importBatch)
                ->with('error', 'Options can only be resolved for batches that completed a dry-run.');
        }

        $data = $request->validate([
            'action' => ['required', 'in:add,replace'],
            'field' => ['required', 'string'],
            'value' => ['required', 'string', 'max:150'],
            'replacement' => ['nullable', 'string', 'max:150'],
        ], [
            'action.in' => 'Choose whether to add the value as a new option or replace it.',
            'value.required' => 'The unrecognized value is missing.',
        ]);

        $result = $data['action'] === 'add'
            ? $invalidOptions->addOption($importBatch, $data['field'], $data['value'], $request->user()?->id)
            : ((string) ($data['replacement'] ?? '') === ''
                ? ['ok' => false, 'message' => 'Choose an existing option to replace the value with.']
                : $invalidOptions->replaceOption($importBatch, $data['field'], $data['value'], (string) $data['replacement'], $request->user()?->id));

        return redirect()->route('imports.show', $importBatch)
            ->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Bypass a failed government-ID conflict: leave the conflicted field blank,
     * import the row, and flag the created beneficiary as missing that field.
     */
    public function bypassRow(Request $request, ImportBatch $importBatch, int $importRow, \App\Services\Import\ImportService $imports): RedirectResponse
    {
        Gate::authorize('apply', $importBatch);

        $row = $importBatch->rows()->whereKey($importRow)->first();

        if ($row === null) {
            return redirect()->route('imports.show', $importBatch)
                ->with('error', 'That row does not belong to this import batch.');
        }

        if ($row->applied) {
            return redirect()->route('imports.show', $importBatch)
                ->with('error', 'That row has already been applied.');
        }

        $payload = $row->payload ?? [];
        $blankFields = [];

        if (trim((string) ($payload['source_id_no'] ?? '')) !== '') {
            $payload['source_id_no'] = null;
            $blankFields[] = 'source_id_no';
        }

        if ($blankFields === []) {
            return redirect()->route('imports.show', $importBatch)
                ->with('error', 'There is no conflicting value to bypass on this row.');
        }

        try {
            $beneficiary = $imports->insertRowAsNew($importBatch, $row, $payload);
        } catch (\Throwable $e) {
            return redirect()->route('imports.show', $importBatch)
                ->with('error', 'The row could not be imported after bypassing the value: '.mb_substr($e->getMessage(), 0, 200));
        }

        $beneficiary->forceFill([
            'missing_fields' => implode(',', $blankFields),
        ])->save();

        $row->forceFill([
            'payload' => $payload,
            'applied' => true,
            'operator_decision' => 'insert',
            'apply_error' => null,
            'review_action' => 'bypass_blank',
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
        ])->save();

        AuditLogger::log('import.row.bypass_blank', 'import_batch', $importBatch->id, [
            'excel_row' => $row->excel_row,
            'blank_fields' => $blankFields,
            'beneficiary_id' => $beneficiary->id,
        ]);

        return redirect()->route('imports.show', $importBatch)
            ->with('success', 'Excel row '.$row->excel_row.' imported with the government ID left blank; the record is flagged as missing a field.');
    }

    /**
     * Record the administrator's decision for one duplicate row.
     */
    public function resolveDuplicate(Request $request, ImportBatch $importBatch, int $importRow, DuplicateReviewService $duplicateReview): RedirectResponse
    {
        Gate::authorize('apply', $importBatch);

        $row = $importBatch->rows()->whereKey($importRow)->first();

        if ($row === null) {
            return redirect()->route('imports.show', $importBatch)
                ->with('error', 'That row does not belong to this import batch.');
        }

        $data = $request->validate([
            'action' => ['required', 'in:skip,update_existing,approve_new'],
        ], [
            'action.in' => 'Choose how to handle this duplicate.',
        ]);

        $result = $duplicateReview->decide($importBatch, $row, $data['action'], $request->user());

        return redirect()->route('imports.show', $importBatch)
            ->with($result['ok'] ? 'success' : 'error', $result['message'])
            ->withFragment('duplicates');
    }

    public function cancel(ImportBatch $importBatch): RedirectResponse
    {
        Gate::authorize('cancel', $importBatch);

        if (in_array($importBatch->status, ['applied', 'cancelled'], true)) {
            return redirect()->route('imports.show', $importBatch);
        }

        $importBatch->update(['status' => 'cancelled']);

        if ($importBatch->stored_path && Storage::disk('local')->exists($importBatch->stored_path)) {
            Storage::disk('local')->delete($importBatch->stored_path);
        }

        AuditLogger::log('import.cancelled', 'import_batch', $importBatch->id, [
            'original_filename' => $importBatch->original_filename,
        ]);

        return redirect()->route('imports.index')->with('success', 'Batch cancelled and the uploaded file was deleted.');
    }

    public function report(ImportBatch $importBatch): StreamedResponse
    {
        Gate::authorize('view', $importBatch);

        $allCandidateIds = $importBatch->rows()->whereNotNull('candidate_ids')->limit(5000)->get()
            ->flatMap(fn ($row) => $row->candidate_ids ?? [])
            ->unique()
            ->all();

        $codeLookup = DB::table('beneficiaries')
            ->whereIn('id', $allCandidateIds)
            ->pluck('beneficiary_code', 'id');

        $rows = $importBatch->rows()->orderBy('excel_row')->get()
            ->map(function ($row) use ($codeLookup) {
                $messages = $row->messages ?? [];
                $first = $messages[0] ?? [];
                $payload = $row->payload ?? [];
                $candidates = collect($row->candidate_ids ?? [])->map(fn ($id) => $codeLookup[$id] ?? ('#'.$id))->implode(' ');

                return [
                    $row->excel_row,
                    $row->outcome,
                    $first['severity'] ?? '',
                    $first['field'] ?? '',
                    $first['text'] ?? '',
                    $payload['source_id_number'] ?? '',
                    trim(($payload['first_name'] ?? '').' '.($payload['middle_name'] ?? '').' '.($payload['last_name'] ?? '')),
                    $payload['birthdate_raw'] ?? ($payload['birthdate'] ?? ''),
                    $candidates,
                    $row->operator_decision ?? '',
                ];
            });

        return CsvExporter::download(
            'import-'.$importBatch->id.'-report-'.now()->format('Ymd-His').'.csv',
            ['excel_row', 'outcome', 'severity', 'field', 'message', 'source_id_number', 'name', 'birthdate_raw', 'candidate_codes', 'operator_decision'],
            $rows,
        );
    }
}
