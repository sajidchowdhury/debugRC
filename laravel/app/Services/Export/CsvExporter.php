<?php

namespace App\Services\Export;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 18 — reusable CSV exporter for admin master-data modules.
 *
 * Produces a streamed CSV download (Symfony StreamedResponse) from an
 * Eloquent query builder, so even large tables can be exported without
 * blowing the memory limit.
 *
 * Design:
 *
 *  - Columns are passed as an associative array `[key => label]`.
 *    The `key` may be either:
 *      • a direct attribute on the model (e.g. 'branch_code'), OR
 *      • a dotted relation path (e.g. 'branch.branch_name') which is
 *        resolved via Eloquent dot-notation (the query MUST eager-load
 *        the relation for efficiency).
 *
 *  - Output is RFC 4180 compliant:
 *      • Fields containing a comma, double-quote, or newline are wrapped
 *        in double quotes, with internal double-quotes escaped as "".
 *      • A UTF-8 BOM (\xEF\xBB\xBF) is prepended so Excel auto-detects
 *        the encoding and doesn't render UTF-8 characters as mojibake.
 *
 *  - The filename is timestamped (e.g. `branches_export_20250119_143022.csv`).
 *
 *  - Rows are streamed via `chunk()` so PHP never holds the full result
 *    set in memory. Each chunk is written row-by-row using `fputcsv()`
 *    on a temporary in-memory stream so escaping is handled by PHP's
 *    native CSV writer (which respects the locale-aware delimiter /
 *    enclosure defaults — we force `,` and `"`).
 */
class CsvExporter
{
    /**
     * Build a streamed CSV download response.
     *
     * @param  string         $filename  Base filename (without extension / timestamp).
     * @param  array<string,string> $columns  Column definitions [key => label].
     * @param  EloquentBuilder $query   Eloquent query builder (already scoped + eager-loaded).
     *
     * @return StreamedResponse
     */
    public static function export(string $filename, array $columns, EloquentBuilder $query): StreamedResponse
    {
        $fullFilename = self::filename($filename);

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $fullFilename . '"',
            'Cache-Control'       => 'no-store, no-cache, must-revalidate',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
        ];

        $callback = function () use ($columns, $query): void {
            // Open a writable PHP stream to stdout (the HTTP response body).
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                // @codeCoverageIgnoreStart
                throw new \RuntimeException('Unable to open php://output stream for CSV export.');
                // @codeCoverageIgnoreEnd
            }

            // UTF-8 BOM — Excel auto-detects encoding and renders UTF-8 correctly.
            fwrite($out, "\xEF\xBB\xBF");

            // Header row.
            $headerRow = array_values($columns);
            self::fputcsv($out, $headerRow);

            // Data rows — chunk to keep memory bounded.
            $query->chunk(500, function ($records) use ($out, $columns): void {
                foreach ($records as $record) {
                    $row = [];
                    foreach (array_keys($columns) as $key) {
                        $row[] = self::extractValue($record, $key);
                    }
                    self::fputcsv($out, $row);
                }
            });

            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Build the timestamped filename.
     * e.g. "branches" → "branches_export_20250119_143022.csv".
     */
    public static function filename(string $base): string
    {
        $base      = Str::slug($base, '_');
        $timestamp = now()->format('Ymd_His');

        return "{$base}_export_{$timestamp}.csv";
    }

    /**
     * Extract a value from the record using dotted relation notation.
     *
     * Supports:
     *   - direct attribute:    'branch_code' → $record->branch_code
     *   - relation chain:      'branch.branch_name' → $record->branch?->branch_name
     *
     * Booleans are stringified as "Yes" / "No" so they're human-readable
     * in spreadsheet apps. Dates are formatted as ISO 8601 (Y-m-d H:i:s).
     * Null values become empty strings.
     */
    private static function extractValue(Model $record, string $key): string
    {
        if (!str_contains($key, '.')) {
            $value = $record->{$key};
        } else {
            $value = $record;
            foreach (explode('.', $key) as $segment) {
                if ($value === null) {
                    break;
                }
                $value = is_object($value) ? ($value->{$segment} ?? null) : null;
            }
        }

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }

    /**
     * Write a CSV row using PHP's native fputcsv with forced RFC 4180
     * enclosure + delimiter. We force the escape character to "\\" so
     * PHP 8.4 (which deprecated the default escape with backslash) keeps
     * producing identical output as earlier PHP versions.
     */
    private static function fputcsv($handle, array $fields): void
    {
        fputcsv($handle, $fields, ',', '"', '\\');
    }
}
