<?php

namespace App\Http\Controllers;

use App\Models\Beneficiary;
use App\Services\AuditLogger;
use App\Services\Export\CsvExporter;
use App\Services\Export\XlsxExporter;
use App\Services\Reports\BeneficiaryReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    private const EXPORT_FORMATS = ['csv', 'xlsx'];

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Beneficiary::class);

        $filters = BeneficiaryReport::filters($request->query());

        $beneficiaries = BeneficiaryReport::query($filters)
            ->paginate(25)
            ->withQueryString();

        return view('reports.index', [
            'filters' => $filters,
            'beneficiaries' => $beneficiaries,
            'summary' => BeneficiaryReport::summary(),
        ]);
    }

    public function export(Request $request): StreamedResponse|BinaryFileResponse
    {
        Gate::authorize('export-data');

        $format = strtolower((string) $request->query('format', 'csv'));
        abort_unless(in_array($format, self::EXPORT_FORMATS, true), 404);

        $filters = BeneficiaryReport::filters($request->query());
        $query = BeneficiaryReport::query($filters);
        $count = (clone $query)->count();

        AuditLogger::log('export.'.$format, null, null, [
            'report' => 'beneficiaries',
            'format' => $format,
            'count' => $count,
            'filters' => array_filter($filters, fn ($value) => $value !== '' && $value !== null),
        ]);

        $filename = 'beneficiaries-'.now()->format('Ymd-His').'.'.$format;

        if ($format === 'xlsx') {
            return XlsxExporter::download(
                $filename,
                BeneficiaryReport::headers(),
                (function () use ($query): \Generator {
                    foreach ($query->cursor() as $beneficiary) {
                        yield BeneficiaryReport::row($beneficiary);
                    }
                })(),
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
    }
}
