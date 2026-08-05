<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Refresh Report Materialized Views — Phase 5 + Phase 6 + Phase 8 + Phase 13.
 *
 * REPORTS-1 — Refactored to issue per-MV `REFRESH MATERIALIZED VIEW
 * CONCURRENTLY` statements from PHP (autocommit mode). The previous
 * implementation called the PL/pgSQL `refresh_all_report_views()` function
 * which:
 *   - CANNOT use CONCURRENTLY inside a function body (PG error 55000 —
 *     a PL/pgSQL function body IS a transaction block, and CONCURRENTLY
 *     is forbidden inside transaction blocks).
 *   - Had NO per-MV exception isolation — one MV failure aborted the
 *     whole function (Gap G6 / G-053).
 *   - Did NOT refresh mv_consolidated_trial_balance (Gap G3 / G-049).
 *   - Did NOT log refresh events to financial_audit_log (Gap G7 /
 *     G-052 / G-054).
 *
 * The PHP path uses CONCURRENTLY (non-blocking readers) and isolates
 * per-MV failures via try/catch + Log::warning. Each successful refresh
 * is logged to financial_audit_log with operation='REFRESH' so the audit
 * chain captures MV recomputes (not just row mutations).
 *
 * Scheduled to run every 5 minutes (see routes/console.php).
 *
 * REPORTS-AUDIT-7 (G-238 / materialized-views.md G15): the prior claim
 * "Also run on-demand after journal postings" was aspirational — no caller
 * wired JournalPostingService to the refresh. The wiring now exists but is
 * opt-in via `config('reports.dashboard.refresh_mvs_after_posting', false)`
 * (default OFF to avoid per-posting performance regression on high-volume
 * deployments). When the flag is true, JournalPostingService::createJournalEntry()
 * calls ReportService::refreshMaterializedViews() after the journal_entry +
 * journal_lines insert, wrapped in try/catch so a refresh failure does not
 * roll back the posting. The 5-minute scheduler remains the canonical
 * refresh path for most deployments.
 *
 * Usage:
 *   php artisan reports:refresh
 */
class RefreshReportViews extends Command
{
    protected $signature = 'reports:refresh';
    protected $description = 'Refresh all report materialized views (concurrently, per-MV isolated, audit-logged)';

    /**
     * The 8 MVs refreshed by this command. Ordered by dependency:
     * mv_ledger_balances first (foundation for TB/P&L/BS), then the
     * rest. mv_consolidated_trial_balance is last (depends on the
     * consolidation subsystem having posted elimination entries).
     */
    private const MV_LIST = [
        'mv_ledger_balances',
        'mv_ar_aging',
        'mv_ap_aging',
        'mv_stock_valuation',
        'mv_journal_entry_summary',
        'mv_branch_intercompany',
        'mv_product_movement_summary',
        'mv_consolidated_trial_balance',
    ];

    public function handle(): int
    {
        $this->info('Refreshing report materialized views (per-MV CONCURRENTLY)...');

        $user = $this->currentUserIdentifier();
        $branchId = $this->currentBranchId();

        $startTotal = microtime(true);
        $succeeded = 0;
        $failed = 0;
        $failures = [];

        foreach (self::MV_LIST as $mvName) {
            $mvStart = microtime(true);
            $ok = $this->refreshSingleMv($mvName);
            $elapsedMs = (int) round((microtime(true) - $mvStart) * 1000);

            if ($ok) {
                $succeeded++;
                $this->logRefreshToAuditLog($mvName, true, $elapsedMs, null, $user, $branchId);
            } else {
                $failed++;
                $failures[] = $mvName;
                // logRefreshToAuditLog already called inside refreshSingleMv on failure
                // (it has the error message there; here we don't have it).
            }
        }

        $totalMs = (int) round((microtime(true) - $startTotal) * 1000);

        $this->info("Refresh complete: {$succeeded} succeeded, {$failed} failed in {$totalMs}ms");
        if ($failed > 0) {
            $this->warn('Failed MVs: ' . implode(', ', $failures));
        }

        Log::info('Report materialized views refreshed', [
            'ms' => $totalMs,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'failures' => $failures,
        ]);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Refresh a single MV with CONCURRENTLY (non-blocking readers).
     * Falls back to plain REFRESH if CONCURRENTLY fails (e.g. missing
     * unique index, or stale snapshot). Returns true on success, false
     * on failure (with Log::warning — non-blocking for the remaining MVs).
     */
    private function refreshSingleMv(string $mvName): bool
    {
        try {
            DB::statement("REFRESH MATERIALIZED VIEW CONCURRENTLY {$mvName}");
            return true;
        } catch (\Throwable $e) {
            // CONCURRENTLY can fail if the MV has no unique index, or if
            // a concurrent REFRESH is already running (ShareLock contention).
            // Fall back to plain REFRESH (blocks readers briefly but works).
            try {
                DB::statement("REFRESH MATERIALIZED VIEW {$mvName}");
                return true;
            } catch (\Throwable $e2) {
                Log::warning("Failed to refresh {$mvName}", [
                    'concurrent_error' => $e->getMessage(),
                    'plain_error' => $e2->getMessage(),
                ]);

                // Log the failure to financial_audit_log so it appears
                // in the audit chain (Gap G7 / G-052 / G-054).
                $this->logRefreshToAuditLog(
                    $mvName,
                    false,
                    0,
                    $e2->getMessage(),
                    $this->currentUserIdentifier(),
                    $this->currentBranchId()
                );

                return false;
            }
        }
    }

    /**
     * INSERT a row into financial_audit_log recording the MV refresh
     * outcome. operation='REFRESH' (added to the CHECK constraint by
     * migration 2026_09_04_000001). record_id=0 (MVs don't have a row
     * identity; 0 is the sentinel for "whole-MV operation").
     */
    private function logRefreshToAuditLog(
        string $mvName,
        bool $success,
        int $elapsedMs,
        ?string $error,
        ?string $performedBy,
        ?int $branchId
    ): void {
        try {
            DB::table('financial_audit_log')->insert([
                'table_name'       => $mvName,
                'operation'        => 'REFRESH',
                'record_id'        => 0,
                'before_data'      => null,
                'after_data'       => json_encode([
                    'status'      => $success ? 'ok' : 'failed',
                    'elapsed_ms'  => $elapsedMs,
                    'error'       => $error,
                    'trigger'     => 'artisan:reports:refresh',
                ]),
                'changed_columns'  => '[]',
                'performed_by'     => $performedBy,
                'db_session_user'  => null,
                'branch_id'        => $branchId,
                'request_path'     => 'cli:reports:refresh',
                'request_ip'       => '127.0.0.1',
                'request_id'       => null,
                'created_at'       => now(),
            ]);
        } catch (\Throwable $e) {
            // Audit-log failure must NEVER abort the refresh loop.
            Log::warning("Failed to log MV refresh to financial_audit_log for {$mvName}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function currentUserIdentifier(): ?string
    {
        // CLI context: no authenticated user. Use the OS user + hostname
        // as the performed_by identifier so audit logs can distinguish
        // scheduler runs from manual artisan runs.
        if (PHP_SAPI === 'cli') {
            $user = get_current_user() ?: 'unknown';
            $host = gethostname() ?: 'unknown';
            return "cli:{$user}@{$host}";
        }

        $userId = session('user_id') ?? null;
        return $userId ? "user:{$userId}" : 'web:anonymous';
    }

    private function currentBranchId(): ?int
    {
        $branchId = session('branch_id');
        if ($branchId !== null) {
            return (int) $branchId;
        }

        // CLI: read from app.branch_id GUC if set by --branch flag.
        try {
            $result = DB::selectOne("SELECT NULLIF(current_setting('app.branch_id', true), '')::int AS branch_id");
            return $result?->branch_id;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
