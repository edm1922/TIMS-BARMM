<?php

namespace App\Services\Export;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * XLSX export. Every data cell is written explicitly as a string so a value
 * starting with "=", "+", "-" or "@" is stored as text and can never execute
 * as a spreadsheet formula (same threat model as CsvExporter).
 *
 * @see CsvExporter for the CSV variant (apostrophe prefix + UTF-8 BOM).
 */
class XlsxExporter
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<list<mixed>>  $rows
     */
    public static function download(string $filename, array $headers, iterable $rows): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Beneficiaries');

        $column = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValueExplicit($column.'1', $header, DataType::TYPE_STRING);
            $sheet->getStyle($column.'1')->getFont()->setBold(true);
            $sheet->getColumnDimension($column)->setAutoSize(true);
            $column++;
        }

        $lastColumn = $column;
        $rowNumber = 2;

        foreach ($rows as $row) {
            $column = 'A';
            foreach ($row as $value) {
                $sheet->setCellValueExplicit($column.$rowNumber, self::toCellString($value), DataType::TYPE_STRING);
                $column++;
            }
            $rowNumber++;
        }

        if ($rowNumber > 2) {
            $sheet->freezePane('A2');
        }

        $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true);
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setName('Calibri');
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setSize(11);

        $base = tempnam(sys_get_temp_dir(), 'tims-export-');
        $path = $base.'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        @unlink($base);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private static function toCellString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return (string) $value;
    }
}
