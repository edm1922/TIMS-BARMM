<?php

namespace App\Services\Import;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Read-only workbook inspection: sheet listing, header detection,
 * merged-cell expansion, data-range discovery.
 */
class WorkbookInspector
{
    /**
     * @return array{
     *   sheets: list<array{name: string, visible: bool, data_rows: int}>,
     *   default_sheet: string,
     *   sheet: string,
     *   header_row: int,
     *   two_row_header: bool,
     *   data_start: int,
     *   data_end: int,
     *   columns: list<array{index: int, letter: string, group: string, sub: string, label: string, default_field: string}>,
     *   detected: bool
     * }
     */
    public function inspect(string $path, ?string $sheetName = null): array
    {
        $spreadsheet = IOFactory::createReaderForFile($path)->load($path);

        try {
            $sheets = [];
            foreach ($spreadsheet->getSheetNames() as $name) {
                $sheet = $spreadsheet->getSheetByName($name);
                $sheets[] = [
                    'name' => $name,
                    'visible' => $sheet->getSheetState() === 'visible',
                    'data_rows' => $sheet->getHighestDataRow(),
                ];
            }

            $visible = array_values(array_filter($sheets, fn (array $s) => $s['visible']));
            $candidates = $visible !== [] ? $visible : $sheets;
            usort($candidates, fn (array $a, array $b) => $b['data_rows'] <=> $a['data_rows']);
            $defaultSheet = $sheetName ?: ($candidates[0]['name'] ?? ($sheets[0]['name'] ?? 'Sheet'));

            $sheet = $spreadsheet->getSheetByName($defaultSheet)
                ?? $spreadsheet->getActiveSheet();

            return $this->inspectSheet($sheets, $defaultSheet, $sheet);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * @param  list<array{name: string, visible: bool, data_rows: int}>  $sheets
     * @return array<string, mixed>
     */
    private function inspectSheet(array $sheets, string $sheetName, Worksheet $sheet): array
    {
        $highestRow = $sheet->getHighestDataRow();
        $highestCol = $sheet->getHighestDataColumn();

        // --- header detection: rows 1..30 ---
        $headerRow = null;
        $twoRow = false;

        for ($r = 1; $r <= min(30, $highestRow); $r++) {
            $labels = [];
            for ($c = 1; $c <= Coordinate::columnIndexFromString($highestCol); $c++) {
                $labels[] = FieldMap::normalizeLabel($this->stringValue($sheet->getCell([$c, $r])));
            }
            $joined = implode('|', array_filter($labels));

            $hasGroup = str_contains($joined, 'name of beneficiary');
            $hasSub = str_contains($joined, 'first name') && str_contains($joined, 'birthdate');
            $hasFlat = str_contains($joined, 'no.') && str_contains($joined, 'first name');

            if ($hasGroup || $hasSub || $hasFlat) {
                $headerRow = $r;
                $next = $r + 1 <= $highestRow ? $this->rowHasLabel($sheet, $r + 1, 'first name') : false;
                // a group row ("Name of Beneficiary") needs the sub-header row
                // below it; a flat row already contains the leaf labels
                $twoRow = $hasGroup && $next;
                break;
            }
        }

        if ($headerRow === null) {
            return [
                'sheets' => $sheets,
                'default_sheet' => $sheetName,
                'sheet' => $sheetName,
                'header_row' => 1,
                'two_row_header' => false,
                'data_start' => 1,
                'data_end' => 0,
                'columns' => [],
                'detected' => false,
            ];
        }

        $subHeaderRow = $twoRow ? $headerRow + 1 : null;
        $dataStart = ($subHeaderRow ?? $headerRow) + 1;

        // --- merged range expansion inside the header zone ---
        $valueAt = function (int $col, int $row) use ($sheet): string {
            return $this->stringValue($sheet->getCell([$col, $row]));
        };

        $expanded = function (int $col, int $row) use ($sheet, $valueAt): string {
            $direct = $valueAt($col, $row);
            if ($direct !== '') {
                return $direct;
            }
            foreach ($sheet->getMergeCells() as $range) {
                $bounds = Coordinate::rangeBoundaries($range);
                [$c1, $r1] = $bounds[0];
                [$c2, $r2] = $bounds[1];
                if ($row >= $r1 && $row <= $r2 && $col >= $c1 && $col <= $c2) {
                    return $valueAt($c1, $r1);
                }
            }

            return '';
        };

        $lastCol = Coordinate::columnIndexFromString($highestCol);
        $columns = [];
        for ($c = 1; $c <= $lastCol; $c++) {
            $group = $expanded($c, $headerRow);
            $sub = $subHeaderRow !== null ? $valueAt($c, $subHeaderRow) : '';
            $label = match (true) {
                $group !== '' && $sub !== '' => $group.' / '.$sub,
                $group !== '' => $group,
                default => $sub,
            };

            if ($label === '') {
                continue;
            }

            $columns[] = [
                'index' => $c,
                'letter' => Coordinate::stringFromColumnIndex($c),
                'group' => $group,
                'sub' => $sub,
                'label' => $label,
                'default_field' => FieldMap::defaultFor($label),
            ];
        }

        // --- data range: stop after 5 consecutive empty rows in the key columns ---
        $dataEnd = $dataStart - 1;
        $empty = 0;
        $keyCol = $this->keyColumn($columns);
        for ($r = $dataStart; $r <= $highestRow; $r++) {
            $hasData = $keyCol !== null && $valueAt($keyCol, $r) !== '';
            if (! $hasData) {
                $hasData = $valueAt(1, $r) !== '' || $valueAt(2, $r) !== '';
            }
            if ($hasData) {
                $dataEnd = $r;
                $empty = 0;
            } elseif (++$empty >= 5) {
                break;
            }
        }

        return [
            'sheets' => $sheets,
            'default_sheet' => $sheetName,
            'sheet' => $sheetName,
            'header_row' => $headerRow,
            'two_row_header' => $twoRow,
            'data_start' => $dataStart,
            'data_end' => max($dataEnd, 0),
            'columns' => $columns,
            'detected' => true,
        ];
    }

    /**
     * @param  list<array{index: int, default_field: string}>  $columns
     */
    private function keyColumn(array $columns): ?int
    {
        foreach ($columns as $column) {
            if ($column['default_field'] === 'first_name') {
                return $column['index'];
            }
        }

        return null;
    }

    private function rowHasLabel(Worksheet $sheet, int $row, string $needle): bool
    {
        $lastCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        for ($c = 1; $c <= $lastCol; $c++) {
            if (str_contains(FieldMap::normalizeLabel($this->stringValue($sheet->getCell([$c, $row]))), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function stringValue(Cell|string $cell): string
    {
        if (is_string($cell)) {
            return trim($cell);
        }

        try {
            $value = $cell->isFormula() ? $cell->getCalculatedValue() : $cell->getValue();
        } catch (\Throwable) {
            $value = $cell->getValue();
        }

        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string) $value);
    }
}
