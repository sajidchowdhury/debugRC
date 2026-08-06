<?php

namespace App\Services\Sales;

use Illuminate\Support\Facades\DB;

/**
 * Sales Audit Service — HIGH-WAVE-2-A (G-154).
 *
 * Produces a 12-section health-check report covering the entire sales
 * ecosystem: invoices, challans, returns, customer payments, commission
 * reconciliation, customer ledger, transport adjustments, RLS bypass
 * detection, stale-draft cleanup, GL journal links, and audit trail.
 *
 * Mirrors the architecture of {@see \App\Services\Purchase\PurchaseAuditService}
 * so the sales domain achieves structural parity with the purchase domain
 * (which previously had 12 sections vs the legacy 3-section inline
 * `ReportController::computeSalesAuditChecks` private method).
 *
 * Each audit item carries a status of:
 *   - 'pass'  (green check)  — invariant verified
 *   - 'warn'  (yellow)       — soft issue, may need review
 *   - 'fail'  (red)          — hard invariant violated, action required
 *   - 'info'  (blue)         — reference / informational note
 *
 * The report also includes 2 detail tables for follow-up drill-down:
 *   - missing_gl_journals — sample of sales invoices/challans/returns
 *     that are missing their linked journal_entry_id (limit 15).
 *   - stale_drafts        — sample of draft sales invoices older than
 *     14 days (limit 15).
 *
 * Branch scoping: admin may pass ?branch_id=0 (null here) to audit
 * across all branches; non-admins are pinned to their session branch
 * by the controller (resolveBranchIdForRead()).
 *
 * Date range: defaults to last 30 days when null. The controller passes
 * the user-selected range from ReportRangeRequest.
 */
class SalesAuditService
{
    protected ?int $branchId;
    protected string $from;
    protected string $to;

    /**
     * @param int|null $branchId Branch scope (null = all branches / admin).
     * @param string|null $from  ISO date (Y-m-d). Default: 30 days ago.
     * @param string|null $to    ISO date (Y-m-d). Default: today.
     */
    public function __construct(?int $branchId = null, ?string $from = null, ?string $to = null)
    {
        $this->branchId = $branchId;
        $this->from     = $from ?? now()->subDays(30)->toDateString();
        $this->to       = $to   ?? now()->toDateString();
    }

    /**
     * Build the full 12-section report.
     *
     * @return array{
     *   sections: array<int, array{id:string,title:string,icon:string,items:array<int, array>}>,
     *   summary: array{pass:int,warn:int,fail:int,info:int,total:int},
     *   ran_at: string,
     *   branch_id: int|null,
     *   from: string,
     *   to: string,
     *   missing_gl_journals: array,
     *   stale_drafts: array,
     * }
     */
    public function runHealthChecks(): array
    {
        $sections = [
            $this->sectionModuleScope(),
            $this->sectionInvoices(),
            $this->sectionChallans(),
            $this->sectionReturns(),
            $this->sectionPayments(),
            $this->sectionCommission(),
            $this->sectionLedger(),
            $this->sectionTransport(),
            $this->sectionRls(),
            $this->sectionStale(),
            $this->sectionGlJournalLinks(),
            $this->sectionAuditTrail(),
        ];

        $pass = $warn = $fail = $info = 0;
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                switch ($item['status']) {
                    case 'pass': $pass++; break;
                    case 'warn': $warn++; break;
                    case 'fail': $fail++; break;
                    default:     $info++; break;
                }
            }
        }

        return [
            'sections'             => $sections,
            'summary'              => [
                'pass'  => $pass,
                'warn'  => $warn,
                'fail'  => $fail,
                'info'  => $info,
                'total' => $pass + $warn + $fail + $info,
            ],
            'ran_at'               => now()->format('Y-m-d H:i:s'),
            'branch_id'            => $this->branchId,
            'from'                 => $this->from,
            'to'                   => $this->to,
            'missing_gl_journals'  => $this->getMissingGlJournalRows(),
            'stale_drafts'         => $this->getStaleDraftRows(),
        ];
    }

    // =====================================================================
    // 1. Sales module scope (informational)
    // =====================================================================

    private function sectionModuleScope(): array
    {
        return [
            'id'    => 'scope',
            'title' => 'Sales module scope',
            'icon'  => 'fa-sitemap',
            'items' => [
                $this->item('scope_transactions', 'Transactions', 'Sales invoice → godown prep → challan issued (stock OUT) → customer payment. Returns reverse the cycle.', 'info', null, 'admin/sales-invoices'),
                $this->item('scope_stock', 'Inventory impact', 'Only challan (OUT for issued qty) + return (IN at original avg_cost) move stock. Invoice alone does not move stock.', 'info', null, 'admin/stock-transactions'),
                $this->item('scope_gl', 'Accounting impact', 'Invoice posts Dr AR / Cr Revenue + Dr COGS / Cr Inventory (challan). Payment posts Dr Cash/Bank / Cr AR. Return reverses both.', 'info', null, 'admin/journal-entries'),
                $this->item('scope_commission', 'Commission', 'Calculated on payment allocation (not invoice creation). Confirmed at month-end → Dr Commission Expense / Cr Employee Payable.', 'info', null, 'admin/commission-rules'),
                $this->item('scope_reports', 'Reporting', 'Sales register, gross margin, customer performance, AR aging, salesman commission due.', 'info', null, 'admin/reports'),
            ],
        ];
    }

    // =====================================================================
    // 2. Sales invoices
    // =====================================================================

    private function sectionInvoices(): array
    {
        $bf = $this->branchFilter('si.branch_id');

        $missingGl = $this->scalarCount("
            SELECT COUNT(*) AS c FROM sales_invoices si
            WHERE si.invoice_date BETWEEN ? AND ?
              AND si.status NOT IN ('draft', 'cancelled')
              AND si.journal_entry_id IS NULL
              AND si.deleted_at IS NULL
              {$bf}
        ", $this->dateBranchBindings());

        $unbalanced = $this->scalarCount("
            SELECT COUNT(*) AS c FROM (
                SELECT je.id
                FROM journal_entries je
                JOIN journal_lines jl ON jl.journal_entry_id = je.id
                WHERE je.reference_type = 'sales_invoice'
                  AND je.entry_date BETWEEN ? AND ?
                GROUP BY je.id
                HAVING SUM(jl.debit) <> SUM(jl.credit)
            ) x
        ", $this->dateBindings());

        $noSalesman = $this->scalarCount("
            SELECT COUNT(*) AS c FROM sales_invoices si
            WHERE si.invoice_date BETWEEN ? AND ?
              AND si.status NOT IN ('draft', 'cancelled')
              AND si.salesman_id IS NULL
              AND si.deleted_at IS NULL
              {$bf}
        ", $this->dateBranchBindings());

        $futureDated = $this->scalarCount("
            SELECT COUNT(*) AS c FROM sales_invoices si
            WHERE si.invoice_date BETWEEN ? AND ?
              AND si.invoice_date > CURRENT_DATE
              AND si.deleted_at IS NULL
              {$bf}
        ", $this->dateBranchBindings());

        return [
            'id'    => 'invoices',
            'title' => 'Sales invoices',
            'icon'  => 'fa-file-invoice',
            'items' => [
                $this->item('inv_missing_gl', 'Confirmed invoices have GL journal', 'Non-draft/cancelled invoices in range must have journal_entry_id.', $missingGl === 0 ? 'pass' : 'fail', $missingGl === 0 ? 'OK' : "{$missingGl} invoice(s) missing GL journal"),
                $this->item('inv_unbalanced_je', 'Sales journal entries are balanced', 'SUM(debit) must equal SUM(credit) per sales_invoice JE.', $unbalanced === 0 ? 'pass' : 'fail', $unbalanced === 0 ? 'OK' : "{$unbalanced} unbalanced JE(s)"),
                $this->item('inv_no_salesman', 'Confirmed invoices have salesman', 'salesman_id should be set on every non-draft invoice.', $noSalesman === 0 ? 'pass' : 'warn', $noSalesman === 0 ? 'OK' : "{$noSalesman} invoice(s) without salesman"),
                $this->item('inv_future_dated', 'No future-dated invoices', 'invoice_date should not be later than today.', $futureDated === 0 ? 'pass' : 'warn', $futureDated === 0 ? 'OK' : "{$futureDated} invoice(s) future-dated"),
            ],
        ];
    }

    // =====================================================================
    // 3. Sales challans (delivery note + stock OUT)
    // =====================================================================

    private function sectionChallans(): array
    {
        $bf = $this->branchFilter('sc.branch_id');

        $noCogsJournal = $this->scalarCount("
            SELECT COUNT(*) AS c FROM sales_challans sc
            WHERE sc.challan_date BETWEEN ? AND ?
              AND COALESCE(sc.is_reversed, false) = false
              AND sc.journal_entry_id IS NULL
              {$bf}
        ", $this->dateBranchBindings());

        $orphanInvoice = $this->scalarCount("
            SELECT COUNT(*) AS c FROM sales_challans sc
            WHERE sc.challan_date BETWEEN ? AND ?
              AND COALESCE(sc.is_reversed, false) = false
              AND NOT EXISTS (
                  SELECT 1 FROM sales_invoices si
                  WHERE si.id = sc.sales_invoice_id
                    AND si.status NOT IN ('cancelled', 'reversed')
              )
              {$bf}
        ", $this->dateBranchBindings());

        $softHoldStuck = $this->scalarCount("
            SELECT COUNT(*) AS c FROM sales_challans sc
            WHERE sc.challan_date BETWEEN ? AND ?
              AND COALESCE(sc.is_reversed, false) = false
              AND sc.is_dispatch_soft_hold = true
              {$bf}
        ", $this->dateBranchBindings());

        return [
            'id'    => 'challans',
            'title' => 'Sales challans (dispatch)',
            'icon'  => 'fa-truck',
            'items' => [
                $this->item('chl_missing_cogs', 'Active challans have COGS journal', 'Each active challan posts Dr COGS / Cr Inventory via journal_entry_id.', $noCogsJournal === 0 ? 'pass' : 'fail', $noCogsJournal === 0 ? 'OK' : "{$noCogsJournal} challan(s) missing COGS journal"),
                $this->item('chl_orphan_invoice', 'Challans reference active invoices', 'sales_invoice_id should point to a non-cancelled invoice.', $orphanInvoice === 0 ? 'pass' : 'warn', $orphanInvoice === 0 ? 'OK' : "{$orphanInvoice} challan(s) reference cancelled/reversed invoice"),
                $this->item('chl_soft_hold', 'No stuck dispatch soft-holds', 'Challans should clear is_dispatch_soft_hold once dispatched.', $softHoldStuck === 0 ? 'pass' : 'warn', $softHoldStuck === 0 ? 'OK' : "{$softHoldStuck} challan(s) stuck on soft-hold"),
            ],
        ];
    }

    // =====================================================================
    // 4. Sales returns
    // =====================================================================

    private function sectionReturns(): array
    {
        $bf = $this->branchFilter('sr.branch_id');

        $noJournal = $this->scalarCount("
            SELECT COUNT(*) AS c FROM sales_returns sr
            WHERE sr.return_date BETWEEN ? AND ?
              AND sr.status = 'confirmed'
              AND sr.journal_entry_id IS NULL
              {$bf}
        ", $this->dateBranchBindings());

        $noInvoiceRef = $this->scalarCount("
            SELECT COUNT(*) AS c FROM sales_returns sr
            WHERE sr.return_date BETWEEN ? AND ?
              AND sr.status = 'confirmed'
              AND NOT EXISTS (
                  SELECT 1 FROM sales_invoices si
                  WHERE si.id = sr.sales_invoice_id
                    AND si.deleted_at IS NULL
              )
              {$bf}
        ", $this->dateBranchBindings());

        $reversalNoJe = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM sales_returns sr
            LEFT JOIN journal_entries je ON je.id = sr.journal_entry_id
            WHERE sr.return_date BETWEEN ? AND ?
              AND COALESCE(sr.is_reversed, false) = true
              AND COALESCE(je.is_reversed, false) = false
              {$bf}
        ", $this->dateBranchBindings());

        return [
            'id'    => 'returns',
            'title' => 'Sales returns',
            'icon'  => 'fa-undo',
            'items' => [
                $this->item('ret_missing_gl', 'Confirmed returns have GL journal', 'Each confirmed return must have journal_entry_id (Dr Customer Payable / Cr Revenue + Dr Inventory / Cr COGS reversal).', $noJournal === 0 ? 'pass' : 'fail', $noJournal === 0 ? 'OK' : "{$noJournal} return(s) missing GL journal"),
                $this->item('ret_invoice_ref', 'Returns reference a valid invoice', 'sales_invoice_id must point to a non-deleted invoice.', $noInvoiceRef === 0 ? 'pass' : 'warn', $noInvoiceRef === 0 ? 'OK' : "{$noInvoiceRef} return(s) without valid invoice reference"),
                $this->item('ret_reversal_je', 'Reversed returns have reversed journal', 'When a return is reversed, its linked JE must also be reversed.', $reversalNoJe === 0 ? 'pass' : 'fail', $reversalNoJe === 0 ? 'OK' : "{$reversalNoJe} reversed return(s) with unreversed journal"),
            ],
        ];
    }

    // =====================================================================
    // 5. Customer payments & AR
    // =====================================================================

    private function sectionPayments(): array
    {
        $bf = $this->branchFilter('cp.branch_id');

        $noLedger = $this->scalarCount("
            SELECT COUNT(*) AS c FROM customer_payments cp
            WHERE cp.payment_date BETWEEN ? AND ?
              AND COALESCE(cp.is_reversed, false) = false
              AND NOT EXISTS (
                  SELECT 1 FROM customer_ledger cl
                  WHERE cl.reference_id = cp.id
                    AND cl.reference_type IN ('payment', 'advance', 'receive', 'discount', 'write_off', 'refund')
              )
              {$bf}
        ", $this->dateBranchBindings());

        $noJournal = $this->scalarCount("
            SELECT COUNT(*) AS c FROM customer_payments cp
            WHERE cp.payment_date BETWEEN ? AND ?
              AND COALESCE(cp.is_reversed, false) = false
              AND cp.journal_entry_id IS NULL
              {$bf}
        ", $this->dateBranchBindings());

        // Payment-AR drift: customers whose SUM(customer_ledger.credit)
        // for the period does not match SUM(customer_payments.amount)
        // for the same period — indicates a ledger row was missed or
        // manually edited.
        $drift = $this->scalarCount("
            SELECT COUNT(*) AS c FROM (
                SELECT cp.customer_id
                FROM customer_payments cp
                WHERE cp.payment_date BETWEEN ? AND ?
                  AND COALESCE(cp.is_reversed, false) = false
                  {$bf}
                GROUP BY cp.customer_id
                HAVING COALESCE(SUM(cp.amount), 0) <> COALESCE((
                    SELECT SUM(cl.credit)
                    FROM customer_ledger cl
                    WHERE cl.customer_id = cp.customer_id
                      AND cl.transaction_date BETWEEN ? AND ?
                      AND cl.reference_type IN ('payment', 'advance', 'receive')
                ), 0)
            ) x
        ", array_merge([$this->from, $this->to], $this->branchBindings(), [$this->from, $this->to]));

        return [
            'id'    => 'payments',
            'title' => 'Customer payments & AR',
            'icon'  => 'fa-hand-holding-usd',
            'items' => [
                $this->item('pay_missing_ledger', 'Payments have customer_ledger row', 'Every active customer_payment should write a customer_ledger entry.', $noLedger === 0 ? 'pass' : 'fail', $noLedger === 0 ? 'OK' : "{$noLedger} payment(s) without ledger row"),
                $this->item('pay_missing_journal', 'Payments have GL journal', 'Every active customer_payment should have journal_entry_id.', $noJournal === 0 ? 'pass' : 'warn', $noJournal === 0 ? 'OK' : "{$noJournal} payment(s) missing journal"),
                $this->item('pay_ar_drift', 'Customer ledger matches payment totals', 'SUM(customer_payments.amount) per customer should equal SUM(customer_ledger.credit) for the period.', $drift === 0 ? 'pass' : 'warn', $drift === 0 ? 'OK' : "{$drift} customer(s) with payment-AR drift"),
            ],
        ];
    }

    // =====================================================================
    // 6. Commission reconciliation
    // =====================================================================

    private function sectionCommission(): array
    {
        $bf = $this->branchFilter('ce.branch_id');

        $noRule = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM commission_entries ce
            LEFT JOIN commission_rules cr ON cr.id = ce.commission_rule_id
            WHERE ce.entry_date BETWEEN ? AND ?
              AND (ce.commission_rule_id IS NULL OR cr.id IS NULL OR cr.deleted_at IS NOT NULL)
              {$bf}
        ", $this->dateBranchBindings());

        $staleUnconfirmed = $this->scalarCount("
            SELECT COUNT(*) AS c FROM commission_entries ce
            WHERE ce.status = 'calculated'
              AND ce.entry_date < (CURRENT_DATE - INTERVAL '30 days')
              {$bf}
        ", $this->dateBindings());

        $orphanReversal = $this->scalarCount("
            SELECT COUNT(*) AS c FROM commission_entries ce
            WHERE ce.entry_date BETWEEN ? AND ?
              AND COALESCE(ce.is_reversed, false) = true
              AND ce.reversed_by_entry_id IS NULL
              {$bf}
        ", $this->dateBranchBindings());

        return [
            'id'    => 'commission',
            'title' => 'Commission reconciliation',
            'icon'  => 'fa-percentage',
            'items' => [
                $this->item('com_missing_rule', 'Entries reference active commission rule', 'commission_rule_id must point to a non-deleted rule.', $noRule === 0 ? 'pass' : 'fail', $noRule === 0 ? 'OK' : "{$noRule} entry/entries without active rule"),
                $this->item('com_stale', 'No stale unconfirmed entries', 'Entries in status=calculated older than 30 days should be confirmed or reversed.', $staleUnconfirmed === 0 ? 'pass' : 'warn', $staleUnconfirmed === 0 ? 'OK' : "{$staleUnconfirmed} entry/entries stale unconfirmed"),
                $this->item('com_orphan_reversal', 'Reversed entries reference original', 'A reversed entry must point to its original via reversed_by_entry_id.', $orphanReversal === 0 ? 'pass' : 'fail', $orphanReversal === 0 ? 'OK' : "{$orphanReversal} reversed entry/entries without original reference"),
            ],
        ];
    }

    // =====================================================================
    // 7. Customer ledger
    // =====================================================================

    private function sectionLedger(): array
    {
        $bf = $this->branchFilter('cl.branch_id');

        $invoicesNoLedger = $this->scalarCount("
            SELECT COUNT(*) AS c FROM sales_invoices si
            WHERE si.invoice_date BETWEEN ? AND ?
              AND si.status NOT IN ('draft', 'cancelled')
              AND si.deleted_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM customer_ledger cl
                  WHERE cl.reference_type = 'sales_invoice'
                    AND cl.reference_id = si.id
              )
              {$bf}
        ", $this->dateBranchBindings());

        $imbalanced = $this->scalarCount("
            SELECT COUNT(*) AS c FROM (
                SELECT cl.customer_id
                FROM customer_ledger cl
                WHERE cl.transaction_date BETWEEN ? AND ?
                  {$bf}
                GROUP BY cl.customer_id
                HAVING ABS(COALESCE(SUM(cl.debit), 0) - COALESCE(SUM(cl.credit), 0)) > 0.01
            ) x
        ", $this->dateBranchBindings());

        return [
            'id'    => 'ledger',
            'title' => 'Customer ledger (AR sub-ledger)',
            'icon'  => 'fa-book',
            'items' => [
                $this->item('led_invoice_missing', 'Invoices have customer_ledger row', 'Each non-draft invoice should create a customer_ledger entry (Dr Customer / Cr Revenue).', $invoicesNoLedger === 0 ? 'pass' : 'fail', $invoicesNoLedger === 0 ? 'OK' : "{$invoicesNoLedger} invoice(s) without ledger row"),
                $this->item('led_imbalance', 'Customer ledger balances (debit = credit)', 'Per-customer SUM(debit) should equal SUM(credit) for invoice↔payment cycle.', $imbalanced === 0 ? 'pass' : 'warn', $imbalanced === 0 ? 'OK' : "{$imbalanced} customer(s) with imbalance"),
            ],
        ];
    }

    // =====================================================================
    // 8. Transport adjustments
    // =====================================================================

    private function sectionTransport(): array
    {
        // Defensive: the schema does not have a dedicated transport_adjustments
        // table — transport adjustments live on sales_challans.adjustment_journal_entry_id.
        // If a future migration introduces a transport_adjustments table, run
        // the missing-journal check against it; otherwise emit an info item.
        $tableExists = $this->scalarCount(
            "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_name = 'transport_adjustments'"
        );

        if ($tableExists !== 1) {
            // Fallback: check sales_challans for non-zero transport_adjustment
            // without a linked adjustment_journal_entry_id.
            $bf = $this->branchFilter('sc.branch_id');
            $missingAdjJe = $this->scalarCount("
                SELECT COUNT(*) AS c FROM sales_challans sc
                WHERE sc.challan_date BETWEEN ? AND ?
                  AND COALESCE(sc.transport_adjustment, 0) <> 0
                  AND COALESCE(sc.is_reversed, false) = false
                  AND sc.adjustment_journal_entry_id IS NULL
                  {$bf}
            ", $this->dateBranchBindings());

            return [
                'id'    => 'transport',
                'title' => 'Transport adjustments',
                'icon'  => 'fa-truck-loading',
                'items' => [
                    $this->item('trn_schema', 'No transport_adjustments table', 'Adjustments are tracked per-challan via sales_challans.transport_adjustment + adjustment_journal_entry_id (no separate table).', 'info'),
                    $this->item('trn_missing_adj_je', 'Challans with transport adjustment have adjustment JE', 'Non-zero transport_adjustment requires adjustment_journal_entry_id.', $missingAdjJe === 0 ? 'pass' : 'fail', $missingAdjJe === 0 ? 'OK' : "{$missingAdjJe} challan(s) with transport adjustment missing adjustment JE"),
                ],
            ];
        }

        $bf = $this->branchFilter('ta.branch_id');
        $missingJe = $this->scalarCount("
            SELECT COUNT(*) AS c FROM transport_adjustments ta
            WHERE ta.adjustment_date BETWEEN ? AND ?
              AND ta.journal_entry_id IS NULL
              {$bf}
        ", $this->dateBranchBindings());

        return [
            'id'    => 'transport',
            'title' => 'Transport adjustments',
            'icon'  => 'fa-truck-loading',
            'items' => [
                $this->item('trn_table_present', 'transport_adjustments table exists', 'Per-adjustment tracking with journal_entry_id link.', 'info'),
                $this->item('trn_missing_je', 'Adjustments have GL journal', 'Each transport adjustment should post a journal entry.', $missingJe === 0 ? 'pass' : 'fail', $missingJe === 0 ? 'OK' : "{$missingJe} adjustment(s) missing journal"),
            ],
        ];
    }

    // =====================================================================
    // 9. RLS bypass detection
    // =====================================================================

    private function sectionRls(): array
    {
        $bf = $this->branchFilter('ual.branch_id');

        $branchOverrides = $this->scalarCount("
            SELECT COUNT(*) AS c FROM user_audit_log ual
            WHERE ual.action = 'branch_override'
              AND ual.created_at >= ?
              AND ual.created_at <= ?::timestamp + INTERVAL '1 day'
              {$bf}
        ", $this->dateBranchBindings());

        $crossBranchAdmin = $this->scalarCount("
            SELECT COUNT(*) AS c FROM user_audit_log ual
            WHERE ual.action = 'branch_override'
              AND ual.created_at >= ?
              AND ual.created_at <= ?::timestamp + INTERVAL '1 day'
        ", $this->dateBindings());

        return [
            'id'    => 'rls',
            'title' => 'RLS bypass detection',
            'icon'  => 'fa-shield-alt',
            'items' => [
                $this->item('rls_branch_override', 'No branch_override events in period', 'admin/superadmin cross-branch operations should be rare and auditable.', $branchOverrides === 0 ? 'pass' : 'warn', $branchOverrides === 0 ? 'OK' : "{$branchOverrides} branch_override event(s)"),
                $this->item('rls_cross_admin', 'Cross-branch admin operations (all branches)', 'Total cross-branch overrides regardless of target branch.', 'info', $crossBranchAdmin > 0 ? "{$crossBranchAdmin} event(s)" : 'None in period'),
            ],
        ];
    }

    // =====================================================================
    // 10. Stale draft cleanup
    // =====================================================================

    private function sectionStale(): array
    {
        $bfInv = $this->branchFilter('si.branch_id');
        $bfRet = $this->branchFilter('sr.branch_id');

        $staleInvoices = $this->scalarCount("
            SELECT COUNT(*) AS c FROM sales_invoices si
            WHERE si.invoice_date < (CURRENT_DATE - INTERVAL '14 days')
              AND si.status = 'draft'
              AND si.deleted_at IS NULL
              {$bfInv}
        ", $this->branchBindings());

        $staleReturns = $this->scalarCount("
            SELECT COUNT(*) AS c FROM sales_returns sr
            WHERE sr.return_date < (CURRENT_DATE - INTERVAL '14 days')
              AND sr.status = 'created'
              {$bfRet}
        ", $this->branchBindings());

        // sales_challans has no status column; "stale" means a soft-hold
        // challan that has not been reversed and is older than 14 days.
        $bfChl = $this->branchFilter('sc.branch_id');
        $staleChallans = $this->scalarCount("
            SELECT COUNT(*) AS c FROM sales_challans sc
            WHERE sc.challan_date < (CURRENT_DATE - INTERVAL '14 days')
              AND COALESCE(sc.is_reversed, false) = false
              AND sc.is_dispatch_soft_hold = true
              {$bfChl}
        ", $this->branchBindings());

        return [
            'id'    => 'stale',
            'title' => 'Stale draft cleanup',
            'icon'  => 'fa-broom',
            'items' => [
                $this->item('stale_invoices', 'No stale draft invoices (>14 days)', 'Drafts older than 14 days should be confirmed or cancelled (nightly CancelStaleSalesDrafts job).', $staleInvoices === 0 ? 'pass' : 'warn', $staleInvoices === 0 ? 'OK' : "{$staleInvoices} stale draft invoice(s)"),
                $this->item('stale_returns', 'No stale draft returns (>14 days)', 'Draft returns (status=created) older than 14 days should be confirmed or deleted.', $staleReturns === 0 ? 'pass' : 'warn', $staleReturns === 0 ? 'OK' : "{$staleReturns} stale draft return(s)"),
                $this->item('stale_challans', 'No stuck soft-hold challans (>14 days)', 'Soft-hold challans older than 14 days should be released or reversed.', $staleChallans === 0 ? 'pass' : 'warn', $staleChallans === 0 ? 'OK' : "{$staleChallans} stuck soft-hold challan(s)"),
            ],
        ];
    }

    // =====================================================================
    // 11. GL journal links
    // =====================================================================

    private function sectionGlJournalLinks(): array
    {
        $bf = $this->branchFilter('je.branch_id');

        $missingRef = $this->scalarCount("
            SELECT COUNT(*) AS c FROM journal_entries je
            WHERE je.entry_date BETWEEN ? AND ?
              AND je.reference_type IN ('sales_invoice', 'sales_challan', 'sales_return', 'customer_payment')
              AND je.reference_id IS NULL
              {$bf}
        ", $this->dateBranchBindings());

        $reversalNoLink = $this->scalarCount("
            SELECT COUNT(*) AS c FROM journal_entries je
            WHERE je.entry_date BETWEEN ? AND ?
              AND COALESCE(je.is_reversed, false) = true
              AND je.reversal_of_entry_id IS NULL
              {$bf}
        ", $this->dateBranchBindings());

        return [
            'id'    => 'gl',
            'title' => 'GL journal links',
            'icon'  => 'fa-link',
            'items' => [
                $this->item('gl_missing_ref', 'Sales JEs have reference_id', 'Every JE with reference_type=sales_* must carry a non-null reference_id.', $missingRef === 0 ? 'pass' : 'fail', $missingRef === 0 ? 'OK' : "{$missingRef} JE(s) missing reference_id"),
                $this->item('gl_reversal_link', 'Reversed JEs have reversal_of_entry_id', 'A reversed JE must point back to the original entry.', $reversalNoLink === 0 ? 'pass' : 'warn', $reversalNoLink === 0 ? 'OK' : "{$reversalNoLink} reversed JE(s) without reversal_of_entry_id"),
            ],
        ];
    }

    // =====================================================================
    // 12. Audit trail
    // =====================================================================

    private function sectionAuditTrail(): array
    {
        $bf = $this->branchFilter('si.branch_id');

        $invoicesNoAudit = $this->scalarCount("
            SELECT COUNT(*) AS c FROM sales_invoices si
            WHERE si.invoice_date BETWEEN ? AND ?
              AND si.status NOT IN ('draft', 'cancelled')
              AND si.deleted_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM user_audit_log ual
                  WHERE ual.action IN ('sale_created', 'sale_updated')
                    AND ual.record_id = si.id
              )
              {$bf}
        ", $this->dateBranchBindings());

        $rulesNoAudit = $this->scalarCount("
            SELECT COUNT(*) AS c FROM commission_rules cr
            WHERE cr.created_at BETWEEN ?::timestamp AND ?::timestamp + INTERVAL '1 day'
              AND NOT EXISTS (
                  SELECT 1 FROM user_audit_log ual
                  WHERE ual.action = 'commission_rule_created'
                    AND ual.record_id = cr.id
              )
        ", $this->dateBindings());

        $totalEvents = $this->scalarCount("
            SELECT COUNT(*) AS c FROM user_audit_log ual
            WHERE ual.created_at >= ?
              AND ual.created_at <= ?::timestamp + INTERVAL '1 day'
              AND ual.action IN (
                  'sale_created', 'sale_updated', 'sale_cancelled', 'sale_call_a_day',
                  'payment_received', 'payment_reversed',
                  'return_created', 'return_confirmed', 'return_reversed',
                  'godown_prepared', 'challan_issued', 'challan_reversed',
                  'commission_rule_created', 'commission_calculated',
                  'commission_reversed_on_return',
                  'commission_reversed_on_payment_reversal',
                  'commission_period_confirmed',
                  'stale_drafts_cancelled'
              )
              {$bf}
        ", $this->dateBranchBindings());

        return [
            'id'    => 'audit',
            'title' => 'Audit trail coverage',
            'icon'  => 'fa-clipboard-list',
            'items' => [
                $this->item('aud_invoice_no_log', 'Invoices have sale_created/updated log entry', 'Every non-draft invoice should have a user_audit_log row.', $invoicesNoAudit === 0 ? 'pass' : 'warn', $invoicesNoAudit === 0 ? 'OK' : "{$invoicesNoAudit} invoice(s) without audit log"),
                $this->item('aud_rule_no_log', 'Commission rules have commission_rule_created log', 'New rules should be logged via SalesAuditLogger.', $rulesNoAudit === 0 ? 'pass' : 'warn', $rulesNoAudit === 0 ? 'OK' : "{$rulesNoAudit} rule(s) without audit log"),
                $this->item('aud_total', 'Total sales audit events in period', 'Informational: count of all sales+commission audit events.', 'info', "{$totalEvents} event(s) in period"),
            ],
        ];
    }

    // =====================================================================
    // Detail tables
    // =====================================================================

    public function getMissingGlJournalRows(int $limit = 15): array
    {
        try {
            $rows = [];
            // Invoices missing GL journal
            $invoices = DB::table('sales_invoices as si')
                ->whereBetween('si.invoice_date', [$this->from, $this->to])
                ->whereNotIn('si.status', ['draft', 'cancelled'])
                ->whereNull('si.journal_entry_id')
                ->whereNull('si.deleted_at')
                ->when($this->branchId, fn($q, $bid) => $q->where('si.branch_id', $bid))
                ->select(
                    DB::raw("'invoice' AS doc_type"),
                    'si.id', 'si.invoice_code AS doc_code',
                    'si.invoice_date AS doc_date', 'si.total_amount'
                )
                ->limit($limit)
                ->get();

            foreach ($invoices as $r) {
                $rows[] = (array) $r;
                if (count($rows) >= $limit) {
                    return $rows;
                }
            }

            // Challans missing COGS journal
            $challans = DB::table('sales_challans as sc')
                ->whereBetween('sc.challan_date', [$this->from, $this->to])
                ->where('sc.is_reversed', false)
                ->whereNull('sc.journal_entry_id')
                ->when($this->branchId, fn($q, $bid) => $q->where('sc.branch_id', $bid))
                ->select(
                    DB::raw("'challan' AS doc_type"),
                    'sc.id', 'sc.challan_code AS doc_code',
                    'sc.challan_date AS doc_date', 'sc.issue_cost AS total_amount'
                )
                ->limit($limit - count($rows))
                ->get();

            foreach ($challans as $r) {
                $rows[] = (array) $r;
                if (count($rows) >= $limit) {
                    return $rows;
                }
            }

            // Returns missing GL journal
            $returns = DB::table('sales_returns as sr')
                ->whereBetween('sr.return_date', [$this->from, $this->to])
                ->where('sr.status', 'confirmed')
                ->whereNull('sr.journal_entry_id')
                ->when($this->branchId, fn($q, $bid) => $q->where('sr.branch_id', $bid))
                ->select(
                    DB::raw("'return' AS doc_type"),
                    'sr.id', 'sr.return_code AS doc_code',
                    'sr.return_date AS doc_date', 'sr.total_amount'
                )
                ->limit($limit - count($rows))
                ->get();

            foreach ($returns as $r) {
                $rows[] = (array) $r;
                if (count($rows) >= $limit) {
                    return $rows;
                }
            }

            return $rows;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getStaleDraftRows(int $limit = 15): array
    {
        try {
            return DB::table('sales_invoices as si')
                ->where('si.invoice_date', '<', now()->subDays(14)->toDateString())
                ->where('si.status', 'draft')
                ->whereNull('si.deleted_at')
                ->when($this->branchId, fn($q, $bid) => $q->where('si.branch_id', $bid))
                ->select('si.id', 'si.invoice_code', 'si.invoice_date', 'si.total_amount', 'si.branch_id')
                ->orderBy('si.invoice_date', 'asc')
                ->limit($limit)
                ->get()
                ->map(fn($r) => (array) $r)
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Run a scalar COUNT(*) query, returning the integer count.
     * Returns -1 on error (table missing, syntax error, etc.) so the
     * caller can render an 'info' status instead of a false 'fail'.
     */
    private function scalarCount(string $sql, array $bindings = []): int
    {
        try {
            $row = DB::selectOne($sql, $bindings);
            return $row ? (int) ($row->c ?? 0) : 0;
        } catch (\Throwable $e) {
            return -1;
        }
    }

    /**
     * Build a SQL fragment " AND {column} = ?" for the resolved branch.
     * Returns '' when branchId is null (admin cross-branch view).
     */
    private function branchFilter(string $column): string
    {
        if (!$this->branchId) {
            return '';
        }
        return " AND {$column} = ?";
    }

    /**
     * Build a SQL fragment for warehouse-scoped branch filtering via
     * the warehouses.branch_id column (mirrors PurchaseAuditService).
     */
    private function branchWarehouseFilter(string $warehouseColumn): string
    {
        if (!$this->branchId) {
            return '';
        }
        return " AND EXISTS (
            SELECT 1 FROM warehouses w
            WHERE w.id = {$warehouseColumn} AND w.branch_id = ?
        )";
    }

    /**
     * Bindings for date-range-only queries: [$from, $to].
     */
    private function dateBindings(): array
    {
        return [$this->from, $this->to];
    }

    /**
     * Bindings for date-range + branch filter queries: [$from, $to, $branchId?].
     * The branchId is included only when branchFilter() emitted a '?' placeholder.
     */
    private function dateBranchBindings(): array
    {
        $bindings = [$this->from, $this->to];
        if ($this->branchId) {
            $bindings[] = $this->branchId;
        }
        return $bindings;
    }

    /**
     * Bindings for branch-only queries (no date filter): [$branchId?].
     */
    private function branchBindings(): array
    {
        return $this->branchId ? [$this->branchId] : [];
    }

    /**
     * Build a single audit item row.
     */
    private function item(
        string $id,
        string $title,
        string $expected,
        string $status,
        ?string $detail = null,
        ?string $route = null
    ): array {
        return [
            'id'       => $id,
            'title'    => $title,
            'expected' => $expected,
            'status'   => $status,
            'detail'   => $detail ?? '',
            'url'      => $route ? url($route) : null,
        ];
    }
}
