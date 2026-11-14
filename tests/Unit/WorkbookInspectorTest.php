<?php

namespace Tests\Unit;

use App\Services\Import\WorkbookInspector;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

class WorkbookInspectorTest extends TestCase
{
    public function test_an_inflated_sheet_dimension_does_not_scan_a_million_empty_rows(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['No.', 'First Name', 'Birthdate'], null, 'A1');
        $sheet->fromArray([1, 'JUAN', '1990-01-01'], null, 'A2');

        // A single stray cell far below the data inflates the sheet dimension.
        $sheet->setCellValue('A1048576', 'STRAY');

        $path = tempnam(sys_get_temp_dir(), 'tims-inspect-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        try {
            $result = (new WorkbookInspector)->inspect($path);

            $this->assertTrue($result['detected']);
            $this->assertLessThanOrEqual(5000, $result['data_end']);
            $this->assertLessThanOrEqual(100, count($result['columns']));
        } finally {
            @unlink($path);
        }
    }
}
