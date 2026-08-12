<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ExportAuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Writes an export_audit_log row — REPORTS-AUDIT-1 (G-132 / csv-export.md G6).
 *
 * Apply this trait to any controller that performs a CSV/JSON/HTML export of
 * business data. The trait's `logExport()` method writes an append-only row
 * to the `export_audit_log` table recording WHO exported WHAT WHEN with
 * which filters. This closes the audit-trail gap on read-only exports — the
 * existing `fn_financial_audit_trigger` only fires on INSERT/UPDATE/DELETE,
 * so SELECT-based exports were previously invisible to the audit trail.
 *
 * Usage:
 *
 *   class PurchaseOrderController extends Controller
 *   {
 *       use \App\Http\Controllers\Concerns\WritesExportAuditLog;
 *
 *       public function export(Request $request)
 *       {
 *           // ... build + return the streamed CSV response ...
 *
 *           $this->logExport('purchase_orders', $request->only([
 *               'from_date', 'to_date', 'branch_id', 'supplier_id', 'status',
 *           ]), rowCount: $pos->count(), byteSize: 0);
 *
 *           return $response;
 *       }
 *   }
 *
 * For streamed responses where row_count + byte_size are unknown until the
 * stream completes, pass 0 for both — the audit row still records THAT an
 * export happened, with the filter context (which is the primary compliance
 * signal). A future enhancement may capture actual row counts via an
 * `ob_start()` wrapper that counts rows as they're emitted.
 *
 * The trait is non-blocking: a failure to write the audit row (e.g. DB
 * connectivity issue, schema drift) is logged via Log::warning and the
 * export proceeds — we don't want a broken audit table to prevent the
 * user's export from completing. CRITICAL: re-throw if inside a
 * DB::transaction() to avoid leaving PostgreSQL in an aborted state.
 *
 * Respects `config('reports.audit.enabled')` — when false (e.g. in
 * staging), the method is a silent no-op.
 */
trait WritesExportAuditLog
{
    /**
     * Write an export_audit_log row.
     *
     * @param  string  $module   Short module label (e.g. 'purchase_orders',
     *                           'budget_variance', 'branch_demand_weekly').
     * @param  array   $filters  Filter inputs as an associative array
     *                           (e.g. ['from_date' => '2026-01-01', 'branch_id' => 5]).
     *                           Stored as JSONB. Pass [] for no filters.
     * @param  int     $rowCount Number of rows exported. 0 if unknown (streamed).
     * @param  int     $byteSize Byte size of the produced file. 0 if unknown (streamed).
     */
    protected function logExport(string $module, array $filters = [], int $rowCount = 0, int $byteSize = 0): void
    {
        // Respect the audit.enabled config flag — when false, the method is
        // a silent no-op (useful for staging / load-test environments where
        // audit-row writes would skew performance numbers).
        if (!config('reports.audit.enabled', true)) {
            return;
        }

        try {
            $request = request();

            ExportAuditLog::create([
                'user_id' => Auth::id(),
                'route' => $request?->path() ?? 'unknown',
                'module' => $module,
                'filters_json' => $filters ?: null,
                'row_count' => $rowCount,
                'byte_size' => $byteSize,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent()
                    ? mb_substr($request->userAgent(), 0, 1000)
                    : null,
                'exported_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // CRITICAL: Re-throw if inside a DB::transaction(), because a
            // swallowed SQL error leaves PostgreSQL in an aborted state (25P02).
            // Only swallow if we are NOT inside a transaction.
            if (DB::transactionLevel() > 0) {
                throw $e;
            }

            // Non-fatal: log the warning + let the export proceed. A broken
            // audit table must not block the user's export. Mirrors the
            // AuditableMasterData trait's error handling pattern.
            Log::warning('WritesExportAuditLog: failed to log export', [
                'module' => $module,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
