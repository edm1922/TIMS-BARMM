<?php

namespace App\Http\Controllers;

use App\Models\Beneficiary;
use App\Models\BeneficiaryBatch;
use App\Services\AuditLogger;
use App\Services\Export\BeneficiaryProfileXlsxExporter;
use App\Services\Export\CsvExporter;
use App\Services\Reports\BeneficiaryReport;
use App\Services\Reports\ReportErrorReporter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ReportController extends Controller
{
    private const EXPORT_FORMATS = ['csv', 'xlsx'];

    public function index(Request $request): View|Response
    {
        Gate::authorize('viewAny', Beneficiary::class);

        $missing = ReportErrorReporter::missingSchema();

        if ($missing !== []) {
            return $this->failure('index', null, $missing, 'This installation\'s database is missing the tables the reports page needs.');
        }

        try {
            $filters = BeneficiaryReport::filters($request->query());

            $perPage = (int) $request->query('per_page', 25);
            $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;

            $beneficiaries = BeneficiaryReport::query($filters)
                ->with('beneficiaryBatch')
                ->paginate($perPage)
                ->withQueryString();

            return view('reports.index', [
                'perPage' => $perPage,
                'filters' => $filters,
                'beneficiaries' => $beneficiaries,
                'batches' => BeneficiaryBatch::query()
                    ->withMemberCount()
                    ->orderBy('name')
                    ->get(),
                'summary' => BeneficiaryReport::summary(),
                'civilStatuses' => Beneficiary::query()
                    ->select('civil_status')
                    ->distinct()
                    ->whereNotNull('civil_status')
                    ->orderBy('civil_status')
                    ->pluck('civil_status'),
            ]);
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return $this->failure('index', $exception);
        }
    }

    public function export(Request $request): StreamedResponse|BinaryFileResponse|Response
    {
        Gate::authorize('export-data');

        try {
            $format = strtolower((string) $request->query('format', 'csv'));
            abort_unless(in_array($format, self::EXPORT_FORMATS, true), 404);

            $filters = BeneficiaryReport::filters($request->query());
            $query = BeneficiaryReport::query($filters);
            $count = (clone $query)->count();
            $batchName = ctype_digit($filters['batch'])
                ? BeneficiaryBatch::query()->whereKey((int) $filters['batch'])->value('name')
                : null;
            $location = BeneficiaryReport::locationWithBatch(
                BeneficiaryReport::locationScope($query),
                $batchName,
            );

            AuditLogger::log('export.'.$format, null, null, [
                'report' => 'beneficiaries',
                'format' => $format,
                'count' => $count,
                'filters' => array_filter($filters, fn ($value) => $value !== '' && $value !== null),
            ]);

            $filename = BeneficiaryReport::exportFilename($location, $count, $format);

            if ($format === 'xlsx') {
                // With "All batches" selected, the operator chooses between a
                // worksheet per batch and one continuous list. A single batch
                // (or only unassigned records) keeps the original single-sheet
                // workbook either way.
                if ($filters['batch'] === '' && $this->xlsxMode($request) === 'per_batch') {
                    $sections = [];

                    foreach (BeneficiaryBatch::query()->orderBy('name')->get(['id', 'name']) as $targetBatch) {
                        $batchQuery = (clone $query)->inBatch((int) $targetBatch->id);

                        if ((clone $batchQuery)->count() === 0) {
                            continue;
                        }

                        $sections[] = [
                            'name' => $targetBatch->name,
                            'beneficiaries' => $batchQuery->cursor(),
                            'location' => BeneficiaryReport::locationWithBatch($location, $targetBatch->name),
                        ];
                    }

                    $unassignedQuery = (clone $query)->whereNull('beneficiary_batch_id');

                    if ((clone $unassignedQuery)->count() > 0) {
                        $sections[] = [
                            'name' => 'Unassigned',
                            'beneficiaries' => $unassignedQuery->cursor(),
                            'location' => $location,
                        ];
                    }

                    if (count($sections) > 1) {
                        return BeneficiaryProfileXlsxExporter::downloadByBatch($filename, $sections);
                    }
                }

                return BeneficiaryProfileXlsxExporter::download(
                    $filename,
                    $query->cursor(),
                    $location,
                );
            }

            return CsvExporter::download(
                $filename,
                BeneficiaryReport::headers(),
                (function () use ($query): \Generator {
                    foreach ($query->cursor() as $beneficiary) {
                        yield BeneficiaryReport::row($beneficiary);
                    }
                })(),
            );
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return $this->failure('export', $exception);
        }
    }

    private function xlsxMode(Request $request): string
    {
        return $request->query('xlsx_mode') === 'single' ? 'single' : 'per_batch';
    }

    /**
     * Explain a failed reports request instead of showing a blank error page.
     * The details are also written to the audit trail, which synchronizes to
     * the central server, so the cause can be reviewed without the offline
     * log file.
     */
    private function failure(string $context, ?Throwable $exception = null, array $missing = [], ?string $message = null): Response
    {
        ReportErrorReporter::report($context, $exception, $missing === [] ? [] : ['missing_schema' => $missing]);

        return response()->view('reports.error', [
            'message' => $message ?? ($exception !== null ? $exception->getMessage() : 'The reports page could not be loaded.'),
            'missing' => $missing !== [] ? $missing : ReportErrorReporter::missingSchema(),
            'pendingMigrations' => ReportErrorReporter::pendingMigrations(),
        ], 500);
    }
}
