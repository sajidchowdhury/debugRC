<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Branch P&L Report Service — Session 8.
 *
 * Produces a consolidated P&L view of an inter-branch relationship:
 * "As Branch A, what did Branch B demand from me, what did they sell,
 * what profit/loss did they make, and how much do they still owe me?"
 *
 * The report joins three data sources:
 *   1. branch_demand_items — what B demanded from A, at what cost_rate
 *      (the supply-side view). The `receiving_branch_id` denormalized
 *      column (added in S7) is the selling branch's id.
 *   2. sales_invoice_items — what B sold, classified by price_classification
 *      (added in S5: min/default/max/below_min). Linked to the demand
 *      item via `branch_demand_item_id` (added in S5, populated in S7).
 *   3. branch_ledger — the running_balance for the A↔B pair, which is
 *      perpetual (not partitioned by FY) and represents the
 *      outstanding due.
 *
 * All queries are scoped to the running fiscal year by the
 * BelongsToFiscalYear trait (S2) — the report does NOT pass an
 * explicit `WHERE fiscal_year_id` clause. This means a closed FY's
 * data is invisible: the global scope hard-blocks reads (S2), and
 * even if a super admin bypasses the scope, the partitions are
 * physically detached (S4) so the rows are gone from the active
 * DB anyway.
 *
 * @see \App\Models\Concerns\BelongsToFiscalYear
 * @see \App\Services\DemandItemFifoResolver
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md Session 8
 */
class BranchPnlReportService
{
    /**
     * Build the consolidated P&L report for the inter-branch relationship
     * (supplier=$branchAId, receiver/seller=$branchBId) in the running
     * fiscal year.
     *
     * @param  int      $branchAId  The supplier branch (Branch A — "view as").
     * @param  int      $branchBId  The selling branch (Branch B — report subject).
     * @param  int|null $fiscalYearId  Optional FY filter (defaults to running FY).
     * @param  string|null $from  Optional start date (Y-m-d) for date-range filter.
     * @param  string|null $to    Optional end date (Y-m-d) for date-range filter.
     * @return array{
     *   demand_summary: array,
     *   sales_summary: array,
     *   outstanding_due: float,
     *   per_demand: array<int, array>,
     * }
     */
    public function forBranch(
        int $branchAId,
        int $branchBId,
        ?int $fiscalYearId = null,
        ?string $from = null,
        ?string $to = null
    ): array {
        if ($branchAId <= 0 || $branchBId <= 0 || $branchAId === $branchBId) {
            return $this->emptyReport();
        }

        // Resolve the fiscal year if not provided. The BelongsToFiscalYear
        // trait will apply to Eloquent queries, but our raw DB queries
        // here need an explicit filter.
        $fyId = $fiscalYearId ?? $this->getRunningFiscalYearId();
        if ($fyId === 0) {
            // No running FY — return empty (typically right after a
            // year-end close before the new FY is activated).
            Log::info('S8 BranchPnlReportService::forBranch — no running FY');
            return $this->emptyReport();
        }

        // ---- Demand summary ----
        // branch_demand_items.fiscal_year_id is on the parent branch_demands
        // (added in S1). We join to filter by FY.
        $demandSummary = DB::table('branch_demand_items as bdi')
            ->join('branch_demands as bd', 'bd.id', '=', 'bdi.branch_demand_id')
            ->where('bd.from_branch_id', $branchBId)        // B is requester (receiver of goods)
            ->where('bd.to_branch_id', $branchAId)          // A is supplier
            ->where('bd.fiscal_year_id', $fyId)
            ->where('bd.is_reversed', false)
            ->when($from, fn ($q) => $q->whereDate('bd.demand_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('bd.demand_date', '<=', $to))
            ->selectRaw("
                COALESCE(SUM(bdi.qty), 0) AS total_demanded_qty,
                COALESCE(SUM(bdi.qty * bdi.cost_rate), 0) AS total_demanded_value,
                COALESCE(SUM(bdi.consumed_qty), 0) AS total_consumed_qty,
                COUNT(DISTINCT bd.id) AS demand_count
            ")
            ->first();

        // ---- Outstanding due from branch_ledger ----
        // branch_ledger is NOT partitioned by FY — running_balance is
        // perpetual. The latest non-reversed entry's running_balance
        // for the (B, A) direction is what B currently owes A.
        // We take the latest row by transaction_date, then id.
        $outstandingRow = DB::table('branch_ledger')
            ->where('from_branch_id', $branchBId)
            ->where('to_branch_id', $branchAId)
            ->where('is_reversed', false)
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->first(['running_balance']);
        $outstandingDue = $outstandingRow ? (float) ($outstandingRow->running_balance ?? 0) : 0.0;

        // ---- Sales summary (aggregate across all linked sale lines) ----
        // sales_invoice_items.branch_demand_item_id → bdi.id
        // (S7 linkage). Only sale lines linked to a demand item from
        // (B, A) in the FY are counted.
        $salesSummary = DB::table('sales_invoice_items as sii')
            ->join('sales_invoices as si', 'si.id', '=', 'sii.sales_invoice_id')
            ->join('branch_demand_items as bdi', 'bdi.id', '=', 'sii.branch_demand_item_id')
            ->join('branch_demands as bd', 'bd.id', '=', 'bdi.branch_demand_id')
            ->where('bd.from_branch_id', $branchBId)
            ->where('bd.to_branch_id', $branchAId)
            ->where('bd.fiscal_year_id', $fyId)
            ->where('bd.is_reversed', false)
            ->where('si.is_reversed', false)
            ->when($from, fn ($q) => $q->whereDate('si.invoice_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('si.invoice_date', '<=', $to))
            ->selectRaw("
                COALESCE(SUM(sii.qty), 0) AS total_sold_qty,
                COALESCE(SUM(sii.qty * sii.rate), 0) AS total_revenue,
                COALESCE(SUM(sii.qty * COALESCE(sii.cost_rate, 0)), 0) AS total_cost,
                COALESCE(SUM(CASE WHEN sii.price_classification = 'min' THEN sii.qty ELSE 0 END), 0) AS qty_at_min,
                COALESCE(SUM(CASE WHEN sii.price_classification = 'default' THEN sii.qty ELSE 0 END), 0) AS qty_at_default,
                COALESCE(SUM(CASE WHEN sii.price_classification = 'max' THEN sii.qty ELSE 0 END), 0) AS qty_at_max,
                COALESCE(SUM(CASE WHEN sii.price_classification = 'below_min' THEN sii.qty ELSE 0 END), 0) AS qty_below_min,
                COUNT(DISTINCT CASE WHEN sii.below_min_override_id IS NOT NULL THEN sii.id END) AS override_count
            ")
            ->first();

        $totalRevenue = (float) ($salesSummary->total_revenue ?? 0);
        $totalCost = (float) ($salesSummary->total_cost ?? 0);

        // ---- Per-demand breakdown ----
        // For each demand from B to A in the FY, compute the demand's
        // own sold_qty / revenue / cost / pl / classification breakdown.
        $perDemand = DB::table('branch_demands as bd')
            ->join('branch_demand_items as bdi', 'bdi.branch_demand_id', '=', 'bd.id')
            ->leftJoin('sales_invoice_items as sii', 'sii.branch_demand_item_id', '=', 'bdi.id')
            ->leftJoin('sales_invoices as si', function ($join) {
                $join->on('si.id', '=', 'sii.sales_invoice_id')
                     ->where('si.is_reversed', '=', false);
            })
            ->where('bd.from_branch_id', $branchBId)
            ->where('bd.to_branch_id', $branchAId)
            ->where('bd.fiscal_year_id', $fyId)
            ->where('bd.is_reversed', false)
            ->when($from, fn ($q) => $q->whereDate('bd.demand_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('bd.demand_date', '<=', $to))
            ->groupBy('bd.id', 'bd.demand_code', 'bd.demand_date', 'bd.status')
            ->orderBy('bd.demand_date', 'asc')
            ->orderBy('bd.id', 'asc')
            ->select([
                'bd.id as demand_id',
                'bd.demand_code',
                'bd.demand_date',
                'bd.status as demand_status',
                DB::raw('COALESCE(SUM(bdi.qty), 0) AS demanded_qty'),
                DB::raw('COALESCE(SUM(bdi.qty * bdi.cost_rate), 0) AS demanded_value'),
                DB::raw('COALESCE(SUM(bdi.consumed_qty), 0) AS consumed_qty'),
                DB::raw('COALESCE(SUM(sii.qty), 0) AS sold_qty'),
                DB::raw('COALESCE(SUM(sii.qty * sii.rate), 0) AS revenue'),
                DB::raw('COALESCE(SUM(sii.qty * COALESCE(sii.cost_rate, 0)), 0) AS cost'),
                DB::raw('COALESCE(SUM(CASE WHEN sii.price_classification = \'min\' THEN sii.qty ELSE 0 END), 0) AS qty_at_min'),
                DB::raw('COALESCE(SUM(CASE WHEN sii.price_classification = \'default\' THEN sii.qty ELSE 0 END), 0) AS qty_at_default'),
                DB::raw('COALESCE(SUM(CASE WHEN sii.price_classification = \'max\' THEN sii.qty ELSE 0 END), 0) AS qty_at_max'),
                DB::raw('COALESCE(SUM(CASE WHEN sii.price_classification = \'below_min\' THEN sii.qty ELSE 0 END), 0) AS qty_below_min'),
                DB::raw('COUNT(DISTINCT CASE WHEN sii.below_min_override_id IS NOT NULL THEN sii.id END) AS override_count'),
            ])
            ->get()
            ->map(function ($r) {
                $revenue = (float) $r->revenue;
                $cost = (float) $r->cost;
                return [
                    'demand_id' => (int) $r->demand_id,
                    'demand_code' => $r->demand_code,
                    'demand_date' => $r->demand_date,
                    'demand_status' => $r->demand_status,
                    'demanded_qty' => (float) $r->demanded_qty,
                    'demanded_value' => (float) $r->demanded_value,
                    'consumed_qty' => (float) $r->consumed_qty,
                    'sold_qty' => (float) $r->sold_qty,
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'pl' => $revenue - $cost,
                    'classification_breakdown' => [
                        'min' => (float) $r->qty_at_min,
                        'default' => (float) $r->qty_at_default,
                        'max' => (float) $r->qty_at_max,
                        'below_min' => (float) $r->qty_below_min,
                    ],
                    'override_count' => (int) $r->override_count,
                ];
            })
            ->toArray();

        return [
            'demand_summary' => [
                'total_demanded_qty' => (float) ($demandSummary->total_demanded_qty ?? 0),
                'total_demanded_value' => (float) ($demandSummary->total_demanded_value ?? 0),
                'total_consumed_qty' => (float) ($demandSummary->total_consumed_qty ?? 0),
                'demand_count' => (int) ($demandSummary->demand_count ?? 0),
                'settled_amount' => max(0, (float) ($demandSummary->total_demanded_value ?? 0) - $outstandingDue),
            ],
            'sales_summary' => [
                'total_sold_qty' => (float) ($salesSummary->total_sold_qty ?? 0),
                'total_revenue' => $totalRevenue,
                'total_cost' => $totalCost,
                'net_pl' => $totalRevenue - $totalCost,
                'qty_at_min' => (float) ($salesSummary->qty_at_min ?? 0),
                'qty_at_default' => (float) ($salesSummary->qty_at_default ?? 0),
                'qty_at_max' => (float) ($salesSummary->qty_at_max ?? 0),
                'qty_below_min' => (float) ($salesSummary->qty_below_min ?? 0),
                'override_count' => (int) ($salesSummary->override_count ?? 0),
            ],
            'outstanding_due' => $outstandingDue,
            'per_demand' => $perDemand,
        ];
    }

    /**
     * Per-demand drilldown: returns the demand header + per-sale-line
     * detail (rate, qty, classification, approver for below-min lines).
     *
     * @return array{
     *   demand: object|null,
     *   sale_lines: array<int, array>,
     *   summary: array,
     * }
     */
    public function forDemand(int $demandId): array
    {
        if ($demandId <= 0) {
            return ['demand' => null, 'sale_lines' => [], 'summary' => []];
        }

        // Load the demand header. The BelongsToFiscalYear trait is not
        // applied to DB::table() queries, so we rely on the caller
        // (controller) to verify FY access via FiscalYearPolicy.
        $demand = DB::table('branch_demands as bd')
            ->join('branches as from_branch', 'from_branch.id', '=', 'bd.from_branch_id')
            ->join('branches as to_branch', 'to_branch.id', '=', 'bd.to_branch_id')
            ->where('bd.id', $demandId)
            ->select([
                'bd.id', 'bd.demand_code', 'bd.demand_date', 'bd.status',
                'bd.from_branch_id', 'bd.to_branch_id',
                'from_branch.branch_name as from_branch_name',
                'to_branch.branch_name as to_branch_name',
                'bd.total_value', 'bd.settlement_amount',
                'bd.is_reversed', 'bd.fiscal_year_id',
            ])
            ->first();

        if (!$demand) {
            return ['demand' => null, 'sale_lines' => [], 'summary' => []];
        }

        // Per-demand-item summary (one row per branch_demand_item).
        $items = DB::table('branch_demand_items as bdi')
            ->leftJoin('sales_invoice_items as sii', 'sii.branch_demand_item_id', '=', 'bdi.id')
            ->leftJoin('sales_invoices as si', function ($join) {
                $join->on('si.id', '=', 'sii.sales_invoice_id')
                     ->where('si.is_reversed', '=', false);
            })
            ->where('bdi.branch_demand_id', $demandId)
            ->groupBy('bdi.id', 'bdi.product_id', 'bdi.qty', 'bdi.cost_rate', 'bdi.consumed_qty')
            ->orderBy('bdi.id', 'asc')
            ->select([
                'bdi.id as demand_item_id',
                'bdi.product_id',
                'bdi.qty as demanded_qty',
                'bdi.cost_rate',
                'bdi.consumed_qty',
                DB::raw('COALESCE(SUM(sii.qty), 0) AS sold_qty'),
                DB::raw('COALESCE(SUM(sii.qty * sii.rate), 0) AS revenue'),
                DB::raw('COALESCE(SUM(sii.qty * COALESCE(sii.cost_rate, 0)), 0) AS cost'),
            ])
            ->get();

        // Per-sale-line detail (for the drilldown table).
        $saleLines = DB::table('sales_invoice_items as sii')
            ->join('sales_invoices as si', 'si.id', '=', 'sii.sales_invoice_id')
            ->join('branch_demand_items as bdi', 'bdi.id', '=', 'sii.branch_demand_item_id')
            ->leftJoin('products as p', 'p.id', '=', 'sii.product_id')
            ->leftJoin('users as approver', 'approver.id', '=', DB::raw(
                "(SELECT ual.user_id FROM user_audit_log ual WHERE ual.id = sii.below_min_override_id LIMIT 1)"
            ))
            ->leftJoin('user_audit_log as ual', 'ual.id', '=', 'sii.below_min_override_id')
            ->where('bdi.branch_demand_id', $demandId)
            ->where('si.is_reversed', false)
            ->orderBy('si.invoice_date', 'asc')
            ->orderBy('sii.id', 'asc')
            ->select([
                'si.id as invoice_id',
                'si.invoice_code',
                'si.invoice_date',
                'sii.id as sale_item_id',
                'sii.product_id',
                'p.product_name',
                'sii.qty',
                'sii.rate',
                'sii.price_min',
                'sii.price_max',
                'sii.price_default',
                'sii.cost_rate',
                'sii.price_classification',
                'sii.below_min_override_id',
                'approver.name as approver_name',
                'ual.details as override_details',
            ])
            ->get()
            ->map(function ($r) {
                $approverReason = null;
                if ($r->override_details) {
                    $decoded = json_decode($r->override_details, true);
                    $approverReason = $decoded['reason'] ?? null;
                }
                return [
                    'invoice_id' => (int) $r->invoice_id,
                    'invoice_code' => $r->invoice_code,
                    'invoice_date' => $r->invoice_date,
                    'sale_item_id' => (int) $r->sale_item_id,
                    'product_id' => (int) $r->product_id,
                    'product_name' => $r->product_name,
                    'qty' => (float) $r->qty,
                    'rate' => (float) $r->rate,
                    'price_min' => $r->price_min !== null ? (float) $r->price_min : null,
                    'price_max' => $r->price_max !== null ? (float) $r->price_max : null,
                    'price_default' => $r->price_default !== null ? (float) $r->price_default : null,
                    'cost_rate' => $r->cost_rate !== null ? (float) $r->cost_rate : null,
                    'price_classification' => $r->price_classification,
                    'below_min_override_id' => $r->below_min_override_id,
                    'approver_name' => $r->approver_name,
                    'override_reason' => $approverReason,
                ];
            })
            ->toArray();

        // Summary cards for this demand.
        $totalDemanded = (float) ($items->sum(fn ($i) => $i->demanded_qty) ?? 0);
        $totalDemandedValue = (float) ($items->sum(fn ($i) => $i->demanded_qty * $i->cost_rate) ?? 0);
        $totalSold = (float) ($items->sum(fn ($i) => $i->sold_qty) ?? 0);
        $totalRevenue = (float) ($items->sum(fn ($i) => $i->revenue) ?? 0);
        $totalCost = (float) ($items->sum(fn ($i) => $i->cost) ?? 0);

        return [
            'demand' => $demand,
            'sale_lines' => $saleLines,
            'summary' => [
                'total_demanded_qty' => $totalDemanded,
                'total_demanded_value' => $totalDemandedValue,
                'total_sold_qty' => $totalSold,
                'total_revenue' => $totalRevenue,
                'total_cost' => $totalCost,
                'net_pl' => $totalRevenue - $totalCost,
                'outstanding' => max(0, (float) ($demand->total_value ?? 0) - (float) ($demand->settlement_amount ?? 0)),
                'consumed_pct' => $totalDemanded > 0 ? round(($totalSold / $totalDemanded) * 100, 1) : 0.0,
            ],
        ];
    }

    /**
     * Get a flat row iterator for CSV export. Yields one row per
     * per-demand entry in the report.
     */
    public function exportRows(int $branchAId, int $branchBId, ?int $fyId = null, ?string $from = null, ?string $to = null): \Generator
    {
        $report = $this->forBranch($branchAId, $branchBId, $fyId, $from, $to);
        foreach ($report['per_demand'] as $row) {
            yield [
                $row['demand_code'],
                $row['demand_date'],
                $row['demand_status'],
                $row['demanded_qty'],
                $row['demanded_value'],
                $row['consumed_qty'],
                $row['sold_qty'],
                $row['revenue'],
                $row['cost'],
                $row['pl'],
                $row['classification_breakdown']['min'],
                $row['classification_breakdown']['default'],
                $row['classification_breakdown']['max'],
                $row['classification_breakdown']['below_min'],
                $row['override_count'],
            ];
        }
    }

    private function getRunningFiscalYearId(): int
    {
        $fy = DB::table('fiscal_years')
            ->where('status', 'active')
            ->orderByDesc('start_date')
            ->first(['id']);
        return $fy ? (int) $fy->id : 0;
    }

    private function emptyReport(): array
    {
        return [
            'demand_summary' => [
                'total_demanded_qty' => 0.0,
                'total_demanded_value' => 0.0,
                'total_consumed_qty' => 0.0,
                'demand_count' => 0,
                'settled_amount' => 0.0,
            ],
            'sales_summary' => [
                'total_sold_qty' => 0.0,
                'total_revenue' => 0.0,
                'total_cost' => 0.0,
                'net_pl' => 0.0,
                'qty_at_min' => 0.0,
                'qty_at_default' => 0.0,
                'qty_at_max' => 0.0,
                'qty_below_min' => 0.0,
                'override_count' => 0,
            ],
            'outstanding_due' => 0.0,
            'per_demand' => [],
        ];
    }
}
