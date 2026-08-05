<?php

/**
 * Reports / CSV Export Pipeline Configuration — REPORTS-AUDIT-1 (G-130 / csv-export.md G5).
 *
 * Single source of truth for the CSV-export knobs that were previously
 * hardcoded across 14+ controllers (BOM bytes, Content-Type, chunk size,
 * filename pattern, default role, throttle limit, date-range cap).
 *
 * Read via `config('reports.csv.<key>')` — NEVER via env() in service code
 * (env() in non-config code breaks `php artisan config:cache`).
 *
 * Surfaced knobs:
 *   - csv.chunk_size          (default 500)   — Eloquent chunk() size for streamed exports
 *   - csv.bom                 (default "\xEF\xBB\xBF") — UTF-8 BOM bytes for Excel auto-detect
 *   - csv.content_type        (default 'text/csv; charset=UTF-8') — HTTP Content-Type header
 *   - csv.filename_pattern    (default '{label}_{timestamp}.csv') — filename template
 *   - csv.default_role        (default 'accountant,manager,admin') — role middleware baseline for export routes
 *   - csv.throttle            (default '10,1') — 10 exports per minute per user (DoS protection)
 *   - csv.max_export_days     (default 365)   — global cap on date-range exports (days)
 *   - audit.enabled           (default true)  — whether export_audit_log rows are written
 *   - audit.retention_days    (default 365)   — how long export_audit_log rows are kept
 *
 * Cross-references:
 *   - AI_CONTEXT/reports/csv-export.md §13 (Future improvements) #2
 *   - ISSUES_REGISTER row G-130 (reports/csv-export.md G5)
 */

return [

    /*
    |--------------------------------------------------------------------------
    | CSV Export Settings
    |--------------------------------------------------------------------------
    |
    | Knobs consumed by App\Services\Export\CsvExporter, the
    | WritesExportAuditLog trait, and the per-module ExportRequest
    | FormRequests. Every value is env-overridable so a deployment can
    | tune them without a code change.
    |
    */

    'csv' => [

        /*
        | Chunk size for Eloquent streamed exports. `CsvExporter::export()`
        | calls `$query->chunk($size, fn($records) => ...)`. Larger chunks
        | mean fewer queries but higher peak memory; smaller chunks mean
        | more queries but lower memory. 500 is the sweet spot on the
        | current dataset (master-data tables < 100k rows).
        */
        'chunk_size' => (int) env('REPORTS_CSV_CHUNK_SIZE', 500),

        /*
        | UTF-8 BOM (Byte Order Mark) — 3 bytes prepended to every CSV so
        | Excel auto-detects UTF-8 encoding and renders Bengali / non-ASCII
        | content correctly. Without it, Excel defaults to ANSI and
        | mojibakes customer/product/branch names.
        |
        | Hex form "\xEF\xBB\xBF" (idiom #1 from csv-export.md §7.5) is
        | the canonical form — most readable, no chr() concat.
        */
        'bom' => env('REPORTS_CSV_BOM', "\xEF\xBB\xBF"),

        /*
        | HTTP Content-Type header. RFC 7231 says charset values are
        | case-insensitive — we standardize on `charset=UTF-8` (capital)
        | to match CsvExporter's existing header.
        */
        'content_type' => env('REPORTS_CSV_CONTENT_TYPE', 'text/csv; charset=UTF-8'),

        /*
        | Filename template. `{label}` is the module slug (e.g. "branches"),
        | `{timestamp}` is now()->format('Ymd_His'). Rendered by
        | CsvExporter::filename().
        */
        'filename_pattern' => env('REPORTS_CSV_FILENAME_PATTERN', '{label}_export_{timestamp}.csv'),

        /*
        | Default role middleware for export routes. Applied at the route
        | group level (e.g. `admin/reports` prefix group already uses
        | `role:accountant,manager,admin` since b3a9fd7). Per-module
        | routes may tighten this (e.g. `role:admin` for global audit export).
        */
        'default_role' => env('REPORTS_CSV_DEFAULT_ROLE', 'accountant,manager,admin'),

        /*
        | Throttle limit for export routes (Laravel throttle middleware
        | format: `limit,decayMinutes`). 10 exports per minute per user
        | is enough for legitimate use (close-of-month bulk exports)
        | while preventing DoS via repeated hits on filtered exports.
        |
        | Applied per-route via `->middleware('throttle:' . config('reports.csv.throttle'))`.
        */
        'throttle' => env('REPORTS_CSV_THROTTLE', '10,1'),

        /*
        | Global cap on date-range exports (days). Prevents a malicious
        | or careless user from requesting `from_date=2000-01-01&to_date=2099-12-31`
        | and triggering memory exhaustion + multi-MB CSV downloads.
        |
        | Per-module FormRequests may tighten this (e.g. BranchDemandExportRequest
        | caps at 90 days). This is the global ceiling.
        */
        'max_export_days' => (int) env('REPORTS_CSV_MAX_EXPORT_DAYS', 365),
    ],

    /*
    |--------------------------------------------------------------------------
    | Export Audit Log Settings
    |--------------------------------------------------------------------------
    |
    | Controls the `export_audit_log` table (G-132 / csv-export.md G6) —
    | an append-only audit trail of every CSV/Parquet/JSON export performed
    | by an authenticated user. Required for SOX/audit-trail compliance on
    | financial-data exports (invoices, trial balance, GL, budget, etc.).
    |
    */

    'audit' => [

        /*
        | Whether export_audit_log rows are written. When false, the
        | WritesExportAuditLog trait's logExport() method is a silent
        | no-op. Useful for staging environments or load-test runs where
        | audit-row writes would skew performance numbers.
        */
        'enabled' => (bool) env('REPORTS_AUDIT_ENABLED', true),

        /*
        | Retention period for export_audit_log rows (days). A scheduled
        | cleanup command (TODO — future session) deletes rows older than
        | this. 365 days = 1 year of export history, sufficient for the
        | annual audit cycle. Increase for longer compliance windows.
        */
        'retention_days' => (int) env('REPORTS_AUDIT_RETENTION_DAYS', 365),
    ],
];
