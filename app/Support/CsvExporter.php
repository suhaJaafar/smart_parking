<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams tabular data as a UTF-8 CSV download.
 *
 * CSV is the pragmatic "Excel export" for this app: it opens natively in
 * Excel, Numbers and Google Sheets, needs zero third-party dependencies,
 * and — critically — renders Arabic park/customer names correctly thanks to
 * the leading UTF-8 BOM (without it, Excel on Windows mangles non-ASCII).
 *
 * Rows are streamed (not buffered) so exporting a large history never blows
 * the memory limit. Pass a generator for `$rows` to keep it lazy end-to-end.
 */
final class CsvExporter
{
    /**
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, string|int|float|null>>  $rows
     */
    public static function stream(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');

            // UTF-8 BOM so Excel detects the encoding and renders Unicode.
            fwrite($out, "\xEF\xBB\xBF");

            // Pass an empty `$escape` (RFC-4180 behaviour) explicitly — PHP 8.4
            // deprecates relying on the legacy default.
            fputcsv($out, $headers, ',', '"', '');

            foreach ($rows as $row) {
                fputcsv($out, array_map(
                    static fn ($value) => $value ?? '',
                    $row,
                ), ',', '"', '');
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
