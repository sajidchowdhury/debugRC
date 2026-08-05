<?php

namespace App\Services\Export;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 18 — reusable CSV exporter for admin master-data modules.
 *
 * REPORTS-AUDIT-1 (G-126 / csv-export.md G3):
 *   - Converted from an all-static class to an instance class registered
 *     as a singleton in AppServiceProvider. The 9 master-data controllers
 *     that previously called `CsvExporter::export(...)` statically now
 *     resolve via the Facade (`\App\Facades\CsvExporter`) which keeps
 *     the call-site syntax identical (`CsvExporter::export(...)`).
 *   - BOM bytes + Content-Type now read from `config('reports.csv.*')`
 *     (with hardcoded fallbacks for the period before the config is
 *     loaded — e.g. during early bootstrap of an Artisan command).
 *   - Added `exportFromRows()` variant for non-Eloquent sources (arrays,
 *     generators, DB cursors) — the foundation for the future refactor
 *     of the 13 inline exports (csv-export.md G11).
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
     * UTF-8 Byte Order Mark — 3 bytes prepended to every CSV so Excel
     * auto-detects UTF-8 encoding. Centralized here so every export
     * path (CsvExporter::export, exportFromRows, and the inline exports
     * that haven't been refactored yet) reads from the same source.
     *
     * Falls back to the hardcoded value if config() returns null (e.g.
     * during early bootstrap before config/reports.php is loaded).
     */
    protected const BOM = "\xEF\xBB\xBF";

    /**
     * Create a new CsvExporter instance.
     *
     * The class is registered as a singleton in AppServiceProvider —
     * resolving via `app(CsvExporter::class)` or the `CsvExporter` Facade
     * returns the same instance every request.
     *
     * Constructor reads config values once at resolution time so we don't
     * call `config()` on every row (minor performance win on large exports).
     */
    public function __construct()
    {
        // Read config once at construction; fall back to hardcoded
        // defaults if the config isn't loaded yet.
        $this->bom = (string) config('reports.csv.bom', self::BOM);
        $this->contentType = (string) config('reports.csv.content_type', 'text/csv; charset=UTF-8');
        $this->chunkSize = (int) config('reports.csv.chunk_size', 500);
    }

    /** @var string UTF-8 BOM bytes (read from config). */
    protected string $bom;

    /** @var string HTTP Content-Type header (read from config). */
    protected string $contentType;

    /** @var int Eloquent chunk() size (read from config). */
    protected int $chunkSize;

    /**
     * Build a streamed CSV download response from an Eloquent query builder.
     *
     * REPORTS-AUDIT-6 (G-218 / csv-export.md G9): the caller is now
     * responsible for building the filename — pass the result of
     * `CsvExporter::filename($label, $parts)` (or any safe pre-built
     * string ending in `.csv`). This method uses the passed filename
     * AS-IS without further slug/timestamp processing.
     *
     * @param  string         $filename  Fully-built filename (with .csv extension). Build via CsvExporter::filename($label, $parts).
     * @param  array<string,string> $columns  Column definitions [key => label].
     * @param  EloquentBuilder $query   Eloquent query builder (already scoped + eager-loaded).
     *
     * @return StreamedResponse
     */
    public function export(string $filename, array $columns, EloquentBuilder $query): StreamedResponse
    {
        $headers = [
            'Content-Type'        => $this->contentType,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-store, no-cache, must-revalidate',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
        ];

        $bom = $this->bom;
        $chunkSize = $this->chunkSize;

        $callback = function () use ($columns, $query, $bom, $chunkSize): void {
            // Open a writable PHP stream to stdout (the HTTP response body).
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                // @codeCoverageIgnoreStart
                throw new \RuntimeException('Unable to open php://output stream for CSV export.');
                // @codeCoverageIgnoreEnd
            }

            // UTF-8 BOM — Excel auto-detects encoding and renders UTF-8 correctly.
            fwrite($out, $bom);

            // Header row.
            $headerRow = array_values($columns);
            $this->fputcsv($out, $headerRow);

            // Data rows — chunk to keep memory bounded.
            $query->chunk($chunkSize, function ($records) use ($out, $columns): void {
                foreach ($records as $record) {
                    $row = [];
                    foreach (array_keys($columns) as $key) {
                        $row[] = $this->extractValue($record, $key);
                    }
                    $this->fputcsv($out, $row);
                }
            });

            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Build a streamed CSV download response from an iterable of pre-built rows.
     *
     * REPORTS-AUDIT-1 (G-150 / csv-export.md G11) — partial resolution.
     * REPORTS-AUDIT-4 — finished the refactor of all remaining 11 inline
     * exports + enhanced with `prepend_rows` + `append_rows` options so
     * multi-section reports (Trial Balance, Cash Flow) can use the same
     * canonical path as simple header+data exports.
     *
     * The full refactor of the 13 inline `fputcsv` exports is too large
     * for one wave. REPORTS-AUDIT-1 laid the foundation + refactored 2
     * exports; REPORTS-AUDIT-4 finished the remaining 11. This method
     * provides the same streaming + BOM + RFC-4180 guarantees as
     * {@see export()} but for non-Eloquent sources — arrays, generators,
     * DB cursors, service pre-built rows.
     *
     * Supported $options keys (all optional):
     *   - 'content_type' : string  — HTTP Content-Type override.
     *   - 'bom'          : string  — BOM bytes override (pass '' to skip BOM).
     *   - 'prepend_rows' : iterable<array> — rows written AFTER the BOM but
     *                   BEFORE the header row. Used by multi-section reports
     *                   (Trial Balance) to write title rows ("Trial Balance
     *                   Report", "Period: X to Y", "Generated: ts") above
     *                   the column header. Each row is fputcsv'd as-is.
     *   - 'append_rows'  : iterable<array> — rows written AFTER the data
     *                   rows. Used by multi-section reports (Trial Balance)
     *                   to write the GRAND TOTAL row, INTEGRITY CHECKS
     *                   block, and SUB-LEDGER RECONCILIATION block after
     *                   the ledger data.
     *
     * Header-row behavior:
     *   - If $headerRow is non-empty, it is written once after the BOM (and
     *     after any prepend_rows).
     *   - If $headerRow is empty ([]), NO header line is written. This
     *     supports reports that have no single fixed column header
     *     (e.g. Cash Flow Statement, which has per-section sub-headers
     *     instead of one global column header). Pass an explicit blank
     *     row via prepend_rows if a separator line is desired.
     *
     * @param  string         $filename  Fully-built filename (with .csv extension). Build via CsvExporter::filename($label, $parts).
     * @param  array<string>  $headerRow Column header labels (single row, written once). Pass [] for no header.
     * @param  iterable<array<string,mixed>> $rows Pre-built data rows (each row is an array of cell values, written in iteration order).
     * @param  array{
     *     'content_type'?: string,
     *     'bom'?: string,
     *     'prepend_rows'?: iterable<array<mixed>>,
     *     'append_rows'?: iterable<array<mixed>>,
     * } $options Optional overrides + multi-section layout rows.
     *
     * @return StreamedResponse
     */
    public function exportFromRows(string $filename, array $headerRow, iterable $rows, array $options = []): StreamedResponse
    {
        $contentType = (string) ($options['content_type'] ?? $this->contentType);
        $bom = (string) ($options['bom'] ?? $this->bom);
        $prependRows = $options['prepend_rows'] ?? [];
        $appendRows  = $options['append_rows']  ?? [];

        $headers = [
            'Content-Type'        => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-store, no-cache, must-revalidate',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
        ];

        $callback = function () use ($headerRow, $rows, $bom, $prependRows, $appendRows): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                // @codeCoverageIgnoreStart
                throw new \RuntimeException('Unable to open php://output stream for CSV export.');
                // @codeCoverageIgnoreEnd
            }

            // UTF-8 BOM — Excel auto-detects encoding and renders UTF-8 correctly.
            if ($bom !== '') {
                fwrite($out, $bom);
            }

            // Prepend rows — written BEFORE the header row. Used by
            // multi-section reports (Trial Balance, Cash Flow) for title
            // rows, period labels, and "Generated: ts" stamps.
            foreach ($prependRows as $row) {
                $this->fputcsv($out, array_values((array) $row));
            }

            // Header row. Skipped when empty so multi-section reports that
            // have no single global column header (e.g. Cash Flow Statement)
            // do not produce a spurious blank line above the first section.
            if (!empty($headerRow)) {
                $this->fputcsv($out, array_values($headerRow));
            }

            // Data rows — iterate one at a time so generators / cursors
            // never load the full result set into memory.
            foreach ($rows as $row) {
                $this->fputcsv($out, array_values((array) $row));
            }

            // Append rows — written AFTER the data rows. Used by multi-section
            // reports for totals, integrity-checks block, and sub-ledger
            // reconciliation sections.
            foreach ($appendRows as $row) {
                $this->fputcsv($out, array_values((array) $row));
            }

            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Build a safe timestamped CSV filename from a label + optional parts.
     *
     * REPORTS-AUDIT-6 (G-218 / csv-export.md G9): the helper now accepts
     * an optional `$parts` array. Each part is slugified individually via
     * `Str::slug($part, '_')` so user-supplied components (branch_id,
     * from_date, to_date, fiscal_year, etc.) cannot inject raw characters
     * into the resulting filename. Even with FormRequest validation
     * upstream, defense-in-depth dictates the slug runs on every part.
     *
     * Output pattern: `{label_slug}_{part1_slug}_{part2_slug}_..._{ts}.csv`
     * where `{ts}` is `now()->format('Y-m-d_His')` (ISO-friendly timestamp
     * that sorts lexicographically and is human-readable in a file listing).
     *
     * Examples:
     *   - `filename('branches')`
     *       → `branches_2026-09-06_143052.csv`
     *   - `filename('branch_demand_weekly', [$branchId, $dateFrom, 'to', $dateTo])`
     *       → `branch_demand_weekly_1_2025-01-01_to_2025-01-31_2026-09-06_143052.csv`
     *   - `filename('Trial_Balance', [$report['meta']['from_date'], 'to', $report['meta']['to_date']])`
     *       → `Trial_Balance_2025-01-01_to_2025-01-31_2026-09-06_143052.csv`
     *
     * Empty parts (slug to '') are skipped so the filename does not gain
     * spurious double underscores.
     *
     * The caller is responsible for invoking this helper BEFORE passing
     * the resulting string to {@see export()} or {@see exportFromRows()}.
     * Those methods accept a fully-built filename as-is (no further
     * processing) — they no longer call `filename()` internally.
     *
     * The filename pattern is documented in `config/reports.php` under
     * `csv.filename_pattern` as `'{label}_{parts}_{timestamp}.csv'`.
     */
    public function filename(string $label, array $parts = []): string
    {
        $segments = [Str::slug($label, '_')];

        foreach ($parts as $part) {
            $slug = Str::slug((string) $part, '_');
            if ($slug !== '') {
                $segments[] = $slug;
            }
        }

        $segments[] = now()->format('Y-m-d_His');

        return implode('_', $segments) . '.csv';
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
    protected function extractValue(Model $record, string $key): string
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
    protected function fputcsv($handle, array $fields): void
    {
        fputcsv($handle, $fields, ',', '"', '\\');
    }
}
