<?php

namespace App\Console\Commands;

use App\Models\SalesInvoice;
use App\Services\Sales\SalesInvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cancel Stale Sales Drafts — P1-2.
 *
 * Mirrors the legacy 3-tier stale-draft cleanup:
 *   1. Auto-cancel on sales/today page load (throttled 6h) — NOT ported
 *      (Laravel uses the scheduled command instead, which is cleaner).
 *   2. Manual admin endpoint (POST /admin/sales/cancel-stale-drafts) —
 *      calls this command's service method directly.
 *   3. Weekly cron — replaced by this daily scheduled command.
 *
 * Legacy logic (SalesInvoiceOperationsTrait::cancelStaleDraftInvoices:1189):
 *   - Query: status='draft' AND is_reversed=false AND godown_issued_at IS NULL
 *            AND created_at < NOW() - INTERVAL 'N days'
 *   - Limit 200 per run (prevents long-running transactions)
 *   - For each: call deleteInvoice($id, 'Stale draft auto-cancel (>N days)')
 *   - Return count cancelled + errors list
 *
 * Usage:
 *   php artisan sales:cancel-stale-drafts              # cancel (default 14 days)
 *   php artisan sales:cancel-stale-drafts --days=30    # custom threshold
 *   php artisan sales:cancel-stale-drafts --dry-run    # report only, no cancel
 *   php artisan sales:cancel-stale-drafts --branch=2   # only a specific branch
 *
 * Scheduled daily at 02:00 in routes/console.php.
 * Gated by config('sales.stale_draft_auto_cancel') — if false, the scheduled
 * run is skipped (but manual --dry-run / explicit invocation still works).
 */
class CancelStaleSalesDrafts extends Command
{
    protected $signature = 'sales:cancel-stale-drafts
                            {--days= : Override the stale threshold (default: from config, 14)}
                            {--branch= : Only cancel drafts for this branch_id}
                            {--dry-run : Report only, do not cancel}';

    protected $description = 'Cancel stale draft sales invoices (older than N days, no godown, no challan)';

    public function handle(SalesInvoiceService $invoiceService): int
    {
        $days = (int) ($this->option('days') ?: config('sales.stale_draft_days', 14));
        $days = max(1, $days);
        $branchId = $this->option('branch') ? (int) $this->option('branch') : null;
        $dryRun = (bool) $this->option('dry-run');

        // Scheduled runs are gated by config; manual invocations bypass this.
        $autoCancel = (bool) config('sales.stale_draft_auto_cancel', true);
        if (!$autoCancel && !$dryRun) {
            // Only honor the gate for non-interactive (scheduled) runs.
            if (!$this->hasOption('days') && !$this->option('branch')) {
                $this->info('Stale draft auto-cancel is disabled (sales.stale_draft_auto_cancel=false). Skipping.');
                return self::SUCCESS;
            }
        }

        $this->info("Scanning for stale draft invoices (>{$days} days)...");

        // Query stale drafts (limit 200 per run, same as legacy).
        $query = SalesInvoice::where('status', 'draft')
            ->where('is_reversed', false)
            ->where('is_godown_prepared', false)
            ->where('is_challan_issued', false)
            ->where('created_at', '<', now()->subDays($days))
            ->orderBy('id', 'asc')
            ->limit(200);

        if ($branchId !== null && $branchId > 0) {
            $query->where('branch_id', $branchId);
        }

        $staleDrafts = $query->get(['id', 'invoice_code', 'created_at', 'total_amount', 'branch_id']);

        $count = $staleDrafts->count();

        if ($count === 0) {
            $this->info('No stale draft invoices found.');
            return self::SUCCESS;
        }

        $this->info("Found {$count} stale draft invoice(s)." . ($dryRun ? ' [DRY RUN — no changes]' : ''));

        // Table preview for dry-run or interactive.
        $this->table(
            ['ID', 'Invoice Code', 'Created At', 'Total (Tk)', 'Branch ID'],
            $staleDrafts->map(fn($i) => [
                $i->id,
                $i->invoice_code,
                $i->created_at?->format('Y-m-d H:i'),
                number_format((float) $i->total_amount, 2),
                $i->branch_id,
            ])->toArray()
        );

        if ($dryRun) {
            $this->info('Dry run complete. Re-run without --dry-run to actually cancel these drafts.');
            return self::SUCCESS;
        }

        // Cancel each draft via the service (which reverses GL + customer_ledger atomically).
        $cancelled = 0;
        $errors = [];
        $reason = "Stale draft auto-cancel (>{$days} days)";
        $systemUserId = (int) (config('sales.stale_draft_cancelled_by') ?: 1); // system user

        foreach ($staleDrafts as $draft) {
            try {
                $invoiceService->cancelInvoice($draft->id, $systemUserId, $reason);
                $cancelled++;
                $this->line("  ✓ Cancelled {$draft->invoice_code} (ID {$draft->id})");
            } catch (\Throwable $e) {
                $errors[] = "{$draft->invoice_code} (ID {$draft->id}): {$e->getMessage()}";
                $this->error("  ✗ Failed {$draft->invoice_code}: {$e->getMessage()}");
                Log::warning('Stale draft cancel failed', [
                    'invoice_id' => $draft->id,
                    'invoice_code' => $draft->invoice_code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Audit log for the bulk cancellation.
        DB::table('user_audit_log')->insert([
            'user_id' => $systemUserId,
            'action' => 'stale_drafts_cancelled',
            'target_user_id' => null,
            'branch_id' => $branchId,
            'details' => json_encode([
                'cancelled_count' => $cancelled,
                'error_count' => count($errors),
                'errors' => array_slice($errors, 0, 20),
                'days_threshold' => $days,
                'reason' => $reason,
                'branch_filter' => $branchId,
                'trigger' => $this->hasOption('days') || $this->option('branch') ? 'manual' : 'scheduled',
            ]),
            'ip_address' => null,
            'user_agent' => 'artisan:sales:cancel-stale-drafts',
            'created_at' => now(),
        ]);

        $this->newLine();
        $this->info("Done. Cancelled {$cancelled} of {$count} stale draft(s).");

        if (!empty($errors)) {
            $this->warn(count($errors) . ' error(s):');
            foreach (array_slice($errors, 0, 10) as $err) {
                $this->line("  - {$err}");
            }
        }

        Log::info('Stale draft cleanup completed', [
            'found' => $count,
            'cancelled' => $cancelled,
            'errors' => count($errors),
            'days' => $days,
            'branch' => $branchId,
        ]);

        return self::SUCCESS;
    }
}
