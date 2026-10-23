<?php

namespace App\Services\Export;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export with UTF-8 BOM (Excel-safe) and OWASP formula-injection
 * protection: cells starting with =, +, -, @, tab or CR get a leading
 * apostrophe.
 */
class CsvExporter
{
    public static function sanitizeCell(mixed $value): string
    {
        $value = $value === null ? '' : (string) $value;

        if ($value === '') {
            return '';
        }

        $first = $value[0];

        if (in_array($first, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<list<mixed>>  $rows
     */
    public static function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($out, array_map([self::class, 'sanitizeCell'], $headers));
            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'sanitizeCell'], $row));
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
