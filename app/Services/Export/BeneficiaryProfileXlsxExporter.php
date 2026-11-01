<?php

namespace App\Services\Export;

use App\Models\Beneficiary;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Renders the beneficiary report as the DOLE "Annex D — Profile of TUPAD
 * Beneficiaries" form (see Sample format.xlsx at the repository root).
 *
 * Every data cell is written explicitly as a string so a value starting with
 * "=", "+", "-" or "@" is stored as text and can never execute as a
 * spreadsheet formula (same threat model as CsvExporter).
 */
class BeneficiaryProfileXlsxExporter
{
    public const FIRST_DATA_ROW = 15;

    /**
     * Column widths copied from Sample format.xlsx.
     *
     * @var array<string, float>
     */
    private const COLUMN_WIDTHS = [
        'A' => 31.43, 'B' => 8.43, 'C' => 5.71, 'D' => 19.71, 'E' => 25.86,
        'F' => 16.86, 'G' => 10.29, 'H' => 15.29, 'I' => 10.43, 'J' => 22.86,
        'K' => 9.43, 'L' => 18.0, 'M' => 23.57, 'N' => 13.29,
        'O' => 20.29, 'P' => 19.29, 'Q' => 20.43, 'R' => 9.0, 'S' => 10.43,
        'T' => 6.29, 'U' => 9.14, 'V' => 19.57, 'W' => 19.29, 'X' => 8.43,
    ];

    /**
     * @var array<int, float>
     */
    private const TITLE_ROW_HEIGHTS = [
        1 => 35.25, 2 => 7.5, 3 => 35.25, 4 => 8.25, 5 => 33.0,
        6 => 23.25, 7 => 23.25, 8 => 23.25, 9 => 23.25, 10 => 23.25,
    ];

    /**
     * @param  iterable<Beneficiary>  $beneficiaries
     * @param  array{province?: ?string, municipality?: ?string, barangay?: ?string}  $location
     */
    public static function download(string $filename, iterable $beneficiaries, array $location = []): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sheet1');

        self::applyColumns($sheet);
        self::writeTitleBlock($sheet, $location);
        self::writeHeader($sheet);
        self::writeRows($sheet, $beneficiaries);
        self::applyPageSetup($sheet);

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

    private static function applyColumns(Worksheet $sheet): void
    {
        foreach (self::COLUMN_WIDTHS as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        foreach (self::TITLE_ROW_HEIGHTS as $row => $height) {
            $sheet->getRowDimension($row)->setRowHeight($height);
        }

        $sheet->getRowDimension(13)->setRowHeight(18);
        $sheet->getRowDimension(14)->setRowHeight(39);
    }

    /**
     * Rows 1-10: the DOLE form masthead. Values are only printed when the
     * filtered export covers a single province / municipality / barangay, so a
     * mixed selection never claims a location it does not have.
     *
     * @param  array{province?: ?string, municipality?: ?string, barangay?: ?string}  $location
     */
    private static function writeTitleBlock(Worksheet $sheet, array $location): void
    {
        $lines = [
            'C1' => 'Annex D',
            'C3' => 'Profile of TUPAD Beneficiaries',
            'C6' => 'Nature of Project: ',
            'C7' => 'DOLE Regional Office: XII',
            'C8' => 'Province: '.($location['province'] ?? ''),
            'C9' => 'Municipality: '.($location['municipality'] ?? ''),
            'C10' => 'Barangay: '.($location['barangay'] ?? ''),
        ];

        foreach ($lines as $cell => $text) {
            $sheet->setCellValueExplicit($cell, $text, DataType::TYPE_STRING);
            $sheet->mergeCells($cell.':X'.substr($cell, 1));
        }

        $sheet->getStyle('C1')->getFont()->setName('Arial Narrow')->setSize(22)->setBold(true);
        $sheet->getStyle('C3')->getFont()->setName('Arial Narrow')->setSize(28)->setBold(true);
        $sheet->getStyle('C6:C10')->getFont()->setName('Arial Narrow')->setSize(18);

        foreach (['C1', 'C3'] as $cell) {
            $sheet->getStyle($cell)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);
        }

        $sheet->getStyle('C6:C10')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
    }

    /**
     * Rows 13-14: the two-row merged header of the DOLE form.
     */
    private static function writeHeader(Worksheet $sheet): void
    {
        $groupHeaders = [
            'B13' => 'COS NUMBER',
            'C13' => 'No.',
            'D13' => 'Name of Beneficiary',
            'H13' => 'Birthdate',
            'I13' => 'Project Location',
            'L13' => 'Type of ID',
            'M13' => 'ID No.',
            'N13' => 'Contact No.',
            'O13' => 'Mode of payment',
            'P13' => 'Type of Beneficiary',
            'Q13' => 'Occupation',
            'R13' => 'Sex',
            'S13' => 'Civil Status',
            'T13' => 'Age',
            'U13' => 'Average monthly income',
            'V13' => 'Dependent',
            'W13' => "Interested in wage employment or self-employment?\n(Yes/No, if Yes, please specify)",
            'X13' => 'Skills Training Needed',
        ];

        $subHeaders = [
            'D14' => 'First Name',
            'E14' => 'Middle Name',
            'F14' => 'Last Name',
            'G14' => 'Extension Name',
            'H14' => '(YYYY/MM/DD)',
            'I14' => 'Brgy.',
            'J14' => 'City/Municipality',
            'K14' => 'Province',
        ];

        foreach ($groupHeaders + $subHeaders + ['A14' => 'ID NUMBER'] as $cell => $text) {
            $sheet->setCellValueExplicit($cell, $text, DataType::TYPE_STRING);
        }

        $merges = [
            'B13:B14', 'C13:C14', 'D13:G13', 'I13:K13',
            'L13:L14', 'M13:M14', 'N13:N14', 'O13:O14', 'P13:P14',
            'Q13:Q14', 'R13:R14', 'S13:S14', 'T13:T14', 'U13:U14',
            'V13:V14', 'W13:W14', 'X13:X14',
        ];

        foreach ($merges as $merge) {
            $sheet->mergeCells($merge);
        }

        $sheet->getStyle('B13:X14')->getFont()
            ->setName('Calibri')
            ->setSize(12)
            ->setBold(true);
        $sheet->getStyle('B13:X14')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
        $sheet->getStyle('B13:X14')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFC9DAF8');
        $sheet->getStyle('B13:X14')->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()->setARGB('FF000000');

        $sheet->getStyle('B13')->getFill()->getStartColor()->setARGB('FFC5D3FF');
        $sheet->getStyle('B13')->getFont()->setSize(11);

        $sheet->getStyle('A14')->getFont()->setName('Calibri')->setSize(11)->setBold(true);
        $sheet->getStyle('A14')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
    }

    /**
     * @param  iterable<Beneficiary>  $beneficiaries
     */
    private static function writeRows(Worksheet $sheet, iterable $beneficiaries): void
    {
        $row = self::FIRST_DATA_ROW;
        $number = 1;

        foreach ($beneficiaries as $beneficiary) {
            $sheet->setCellValueExplicit(
                'A'.$row,
                self::text($beneficiary->beneficiary_code),
                DataType::TYPE_STRING,
            );

            $values = [
                'B' => $beneficiary->cos_no,
                'C' => (string) ($beneficiary->source_row_no ?? $number),
                'D' => $beneficiary->first_name,
                'E' => $beneficiary->middle_name,
                'F' => $beneficiary->last_name,
                'G' => self::extension($beneficiary->extension_name),
                'H' => $beneficiary->birthdate?->format('Y/m/d'),
                'I' => $beneficiary->barangay,
                'J' => $beneficiary->city_municipality,
                'K' => $beneficiary->province,
                'L' => $beneficiary->id_type_label,
                'M' => $beneficiary->source_id_no ?: $beneficiary->source_id_number,
                'N' => $beneficiary->contact_no,
                'O' => $beneficiary->mode_of_payment,
                'P' => $beneficiary->beneficiary_type,
                'Q' => $beneficiary->occupation_label,
                'R' => $beneficiary->sex,
                'S' => $beneficiary->civil_status,
                'T' => $beneficiary->computedAge(),
                'U' => self::wholeNumber($beneficiary->avg_monthly_income),
                'V' => $beneficiary->dependent_name,
                'W' => $beneficiary->employment_interest,
                'X' => $beneficiary->skills_training_needed,
            ];

            foreach ($values as $column => $value) {
                $sheet->setCellValueExplicit(
                    $column.$row,
                    self::text($value),
                    DataType::TYPE_STRING,
                );
            }

            $sheet->getRowDimension($row)->setRowHeight(39);
            self::styleDataRow($sheet, $row);

            $row++;
            $number++;
        }
    }

    private static function styleDataRow(Worksheet $sheet, int $row): void
    {
        $sheet->getStyle('A'.$row)->getFont()->setName('Calibri Light')->setSize(14);
        $sheet->getStyle('A'.$row)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);

        $sheet->getStyle('B'.$row.':X'.$row)->getFont()->setName('Calibri')->setSize(11);
        $sheet->getStyle('B'.$row.':X'.$row)->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('B'.$row.':X'.$row)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()->setARGB('FF000000');

        $sheet->getStyle('C'.$row)->getFont()->setSize(12);
        $sheet->getStyle('C'.$row)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setWrapText(true);

        $sheet->getStyle('W'.$row)->getFont()->setSize(12);
        $sheet->getStyle('W'.$row)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setWrapText(true);

        foreach (['B', 'H', 'L', 'R', 'S', 'T', 'U'] as $column) {
            $sheet->getStyle($column.$row)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        foreach (['D', 'E', 'F', 'G', 'I', 'J', 'K', 'M', 'N', 'O', 'P', 'Q', 'V', 'X'] as $column) {
            $sheet->getStyle($column.$row)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_LEFT)
                ->setWrapText(true);
        }
    }

    private static function applyPageSetup(Worksheet $sheet): void
    {
        $setup = $sheet->getPageSetup();
        $setup->setPaperSize(PageSetup::PAPERSIZE_LETTER);
        $setup->setOrientation(PageSetup::ORIENTATION_PORTRAIT);
        $setup->setFitToWidth(1);
        $setup->setFitToHeight(0);
        $setup->setRowsToRepeatAtTopByStartAndEnd(13, 14);
    }

    private static function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return (string) $value;
    }

    private static function extension(?string $extension): ?string
    {
        if ($extension === null || strcasecmp($extension, 'NONE') === 0) {
            return null;
        }

        return $extension;
    }

    private static function wholeNumber(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $text = (string) $value;

        return preg_replace('/\.0+$/', '', $text) ?? $text;
    }
}
