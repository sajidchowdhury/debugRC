<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sales Funnel / Pipeline Dashboard Controller.
 *
 * Visualizes the sales pipeline from draft → godown → challan → paid → closed,
 * with KPIs, funnel charts, conversion rates, velocity metrics, and
 * open opportunities table.
 *
 * Pipeline Stages (mapped from sales_invoices status + boolean flags):
 *   - Draft Cart    (sales_draft_carts, pre-invoice)           — 10% probability
 *   - Draft Invoice (status=draft, !is_godown_prepared)        — 25% probability
 *   - Godown Ready  (status=confirmed, is_godown_prepared, !is_challan_issued) — 50% probability
 *   - Delivered     (status=confirmed, is_challan_issued)      — 75% probability
 *   - Paid / Closed (due_amount = 0)                           — 100% probability
 *   - Cancelled     (status=cancelled)                         — 0% probability
 *   - Reversed      (is_reversed=true)                         — 0% probability
 */
class SalesFunnelController extends Controller
{
    /**
     * Main dashboard view.
     */
    public function index(Request $request)
    {
        $branchId = $request->input('branch_id') ? (int) $request->input('branch_id') : null;
        $from = $request->input('from_date')
            ? \Carbon\Carbon::parse($request->input('from_date'))
            : \Carbon\Carbon::now()->startOfMonth();
        $to = $request->input('to_date')
            ? \Carbon\Carbon::parse($request->input('to_date'))
            : \Carbon\Carbon::now();

        // ============================================================
        // 1. Funnel data: count + value by pipeline stage
        // ============================================================
        $funnelData = $this->getFunnelData($branchId, $from, $to);

        // ============================================================
        // 2. KPI cards
        // ============================================================
        $kpis = $this->getKPIs($branchId, $from, $to);

        // ============================================================
        // 3. Conversion rates between stages
        // ============================================================
        $conversionRates = $this->getConversionRates($branchId, $from, $to);

        // ============================================================
        // 4. Pipeline trend (last 6 months)
        // ============================================================
        $pipelineTrend = $this->getPipelineTrend($branchId);

        // ============================================================
        // 5. Salesman performance (pipeline ownership)
        // ============================================================
        $salesmanPerformance = $this->getSalesmanPerformance($branchId, $from, $to);

        // ============================================================
        // 6. Open opportunities (draft + godown invoices)
        // ============================================================
        $openOpportunities = $this->getOpenOpportunities($branchId, $from, $to);

        // ============================================================
        // 7. Expected revenue forecast (30/60/90 days)
        // ============================================================
        $forecast = $this->getForecast($branchId, $from, $to);

        // Branch filter options
        $branches = DB::table('branches')->whereNull('deleted_at')->orderBy('branch_name')->get();

        return view('admin.reports.sales_funnel', [
            'meta' => [
                'title' => 'Sales Funnel / Pipeline',
                'from_date' => $from->format('Y-m-d'),
                'to_date' => $to->format('Y-m-d'),
            ],
            'funnelData' => $funnelData,
            'kpis' => $kpis,
            'conversionRates' => $conversionRates,
            'pipelineTrend' => $pipelineTrend,
            'salesmanPerformance' => $salesmanPerformance,
            'openOpportunities' => $openOpportunities,
            'forecast' => $forecast,
            'branches' => $branches,
            'selectedBranch' => $branchId,
        ]);
    }

    // ============================================================
    // DATA METHODS
    // ============================================================

    /**
     * Funnel data: count + value grouped by pipeline stage.
     */
    private function getFunnelData(?int $branchId, \Carbon\Carbon $from, \Carbon\Carbon $to): array
    {
        try {
            $sql = "
                SELECT
                    CASE
                        WHEN si.status = 'draft' AND NOT si.is_godown_prepared THEN 'draft'
                        WHEN si.status = 'confirmed' AND si.is_godown_prepared AND NOT si.is_challan_issued THEN 'godown'
                        WHEN si.status = 'confirmed' AND si.is_challan_issued AND si.due_amount > 0 THEN 'delivered'
                        WHEN si.status = 'confirmed' AND si.is_challan_issued AND si.due_amount <= 0 THEN 'paid'
                        WHEN si.status = 'cancelled' THEN 'cancelled'
                        ELSE 'other'
                    END AS stage,
                    COUNT(*) AS cnt,
                    COALESCE(SUM(si.total_amount), 0) AS total_value,
                    COALESCE(SUM(si.due_amount), 0) AS due_value
                FROM sales_invoices si
                WHERE si.invoice_date BETWEEN ? AND ?
                    AND si.is_reversed = false
                    AND si.deleted_at IS NULL
                    " . ($branchId ? "AND si.branch_id = ?" : "") . "
                GROUP BY stage
                ORDER BY CASE stage
                    WHEN 'draft' THEN 1
                    WHEN 'godown' THEN 2
                    WHEN 'delivered' THEN 3
                    WHEN 'paid' THEN 4
                    WHEN 'cancelled' THEN 5
                    ELSE 6
                END
            ";

            $params = [$from->toDateString(), $to->toDateString()];
            if ($branchId) $params[] = $branchId;

            $rows = DB::select($sql, $params);

            // Also count draft carts (pre-invoice stage)
            $cartCount = 0;
            try {
                $cartQuery = DB::table('sales_draft_carts');
                if ($branchId) $cartQuery->where('branch_id', $branchId);
                $cartCount = $cartQuery->count();
            } catch (\Throwable $e) {}

            // Build the full funnel
            $stageDefs = [
                'cart'      => ['label' => 'Draft Carts',   'color' => '#94a3b8', 'prob' => 0.10],
                'draft'     => ['label' => 'Draft Invoices', 'color' => '#6c757d', 'prob' => 0.25],
                'godown'    => ['label' => 'Godown Ready',   'color' => '#f59e0b', 'prob' => 0.50],
                'delivered' => ['label' => 'Delivered',      'color' => '#0ea5e9', 'prob' => 0.75],
                'paid'      => ['label' => 'Paid / Closed',  'color' => '#16a34a', 'prob' => 1.00],
                'cancelled' => ['label' => 'Cancelled',      'color' => '#dc2626', 'prob' => 0.00],
            ];

            $mapped = [];
            foreach ($rows as $r) {
                $key = $r->stage;
                if (isset($stageDefs[$key])) {
                    $mapped[$key] = [
                        'label'       => $stageDefs[$key]['label'],
                        'color'       => $stageDefs[$key]['color'],
                        'probability' => $stageDefs[$key]['prob'],
                        'count'       => (int) $r->cnt,
                        'value'       => (float) $r->total_value,
                        'due'         => (float) $r->due_value,
                        'weighted'    => (float) $r->total_value * $stageDefs[$key]['prob'],
                    ];
                }
            }

            // Add cart stage
            $mapped = array_merge([
                'cart' => [
                    'label'       => 'Draft Carts',
                    'color'       => '#94a3b8',
                    'probability' => 0.10,
                    'count'       => $cartCount,
                    'value'       => 0,
                    'due'         => 0,
                    'weighted'    => 0,
                ],
            ], $mapped);

            return $mapped;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * KPI cards for the pipeline dashboard.
     */
    private function getKPIs(?int $branchId, \Carbon\Carbon $from, \Carbon\Carbon $to): array
    {
        try {
            $baseWhere = "si.invoice_date BETWEEN ? AND ? AND si.is_reversed = false AND si.deleted_at IS NULL";
            $params = [$from->toDateString(), $to->toDateString()];
            $branchFilter = $branchId ? "AND si.branch_id = ?" : "";
            if ($branchId) $params[] = $branchId;

            // Open pipeline value (draft + godown — not yet delivered)
            $openPipeline = DB::selectOne("
                SELECT COUNT(*) AS cnt, COALESCE(SUM(si.total_amount), 0) AS val
                FROM sales_invoices si
                WHERE $baseWhere $branchFilter
                    AND si.status NOT IN ('cancelled')
                    AND NOT si.is_challan_issued
            ", $params);

            // Weighted pipeline (probability-adjusted)
            $weightedPipeline = DB::selectOne("
                SELECT COALESCE(SUM(
                    CASE
                        WHEN si.status = 'draft' AND NOT si.is_godown_prepared THEN si.total_amount * 0.25
                        WHEN si.status = 'confirmed' AND si.is_godown_prepared AND NOT si.is_challan_issued THEN si.total_amount * 0.50
                        ELSE 0
                    END
                ), 0) AS weighted
                FROM sales_invoices si
                WHERE $baseWhere $branchFilter
                    AND si.status NOT IN ('cancelled')
                    AND NOT si.is_challan_issued
            ", $params);

            // Closed won (paid) count and value
            $closedWon = DB::selectOne("
                SELECT COUNT(*) AS cnt, COALESCE(SUM(si.total_amount), 0) AS val
                FROM sales_invoices si
                WHERE $baseWhere $branchFilter
                    AND si.is_challan_issued
                    AND si.due_amount <= 0
            ", $params);

            // Total deals (all non-cancelled, non-reversed)
            $totalDeals = DB::selectOne("
                SELECT COUNT(*) AS cnt FROM sales_invoices si
                WHERE $baseWhere $branchFilter
                    AND si.status NOT IN ('cancelled')
            ", $params);

            // Win rate = closed_won / total_deals * 100
            $totalDealCount = (int) ($totalDeals->cnt ?? 1);
            $closedWonCount = (int) ($closedWon->cnt ?? 0);
            $winRate = $totalDealCount > 0 ? round(($closedWonCount / $totalDealCount) * 100, 1) : 0;

            // Average deal size (of closed won)
            $avgDealSize = $closedWonCount > 0 ? round((float) $closedWon->val / $closedWonCount, 0) : 0;

            // Pipeline velocity: avg days from draft to paid
            $velocity = DB::selectOne("
                SELECT COALESCE(AVG(
                    EXTRACT(EPOCH FROM (si.challan_issued_at - si.created_at)) / 86400
                ), 0) AS avg_days
                FROM sales_invoices si
                WHERE si.challan_issued_at IS NOT NULL
                    AND si.created_at IS NOT NULL
                    AND si.is_reversed = false
                    AND si.deleted_at IS NULL
                    " . ($branchId ? "AND si.branch_id = ?" : "") . "
            ", $branchId ? [$branchId] : []);

            // Stale drafts (>7 days old, still draft)
            $staleDrafts = DB::selectOne("
                SELECT COUNT(*) AS cnt
                FROM sales_invoices si
                WHERE si.status = 'draft'
                    AND si.created_at < (CURRENT_DATE - INTERVAL '7 days')
                    AND si.is_reversed = false
                    AND si.deleted_at IS NULL
                    " . ($branchId ? "AND si.branch_id = ?" : "") . "
            ", $branchId ? [$branchId] : []);

            return [
                'open_pipeline'   => (float) ($openPipeline->val ?? 0),
                'open_count'      => (int) ($openPipeline->cnt ?? 0),
                'weighted_pipeline' => (float) ($weightedPipeline->weighted ?? 0),
                'closed_won'      => (float) ($closedWon->val ?? 0),
                'closed_won_count'=> $closedWonCount,
                'win_rate'        => $winRate,
                'avg_deal_size'   => $avgDealSize,
                'velocity_days'   => round((float) ($velocity->avg_days ?? 0), 1),
                'stale_drafts'    => (int) ($staleDrafts->cnt ?? 0),
            ];
        } catch (\Throwable $e) {
            return [
                'open_pipeline' => 0, 'open_count' => 0,
                'weighted_pipeline' => 0, 'closed_won' => 0,
                'closed_won_count' => 0, 'win_rate' => 0,
                'avg_deal_size' => 0, 'velocity_days' => 0,
                'stale_drafts' => 0,
            ];
        }
    }

    /**
     * Conversion rates between pipeline stages.
     */
    private function getConversionRates(?int $branchId, \Carbon\Carbon $from, \Carbon\Carbon $to): array
    {
        try {
            $baseWhere = "si.invoice_date BETWEEN ? AND ? AND si.is_reversed = false AND si.deleted_at IS NULL";
            $params = [$from->toDateString(), $to->toDateString()];
            $branchFilter = $branchId ? "AND si.branch_id = ?" : "";
            if ($branchId) $params[] = $branchId;

            $counts = DB::selectOne("
                SELECT
                    SUM(CASE WHEN si.status = 'draft' AND NOT si.is_godown_prepared THEN 1 ELSE 0 END) AS draft_count,
                    SUM(CASE WHEN si.is_godown_prepared AND NOT si.is_challan_issued THEN 1 ELSE 0 END) AS godown_count,
                    SUM(CASE WHEN si.is_challan_issued THEN 1 ELSE 0 END) AS delivered_count,
                    SUM(CASE WHEN si.is_challan_issued AND si.due_amount <= 0 THEN 1 ELSE 0 END) AS paid_count
                FROM sales_invoices si
                WHERE $baseWhere $branchFilter
            ", $params);

            $draft    = (int) ($counts->draft_count ?? 0);
            $godown   = (int) ($counts->godown_count ?? 0);
            $delivered = (int) ($counts->delivered_count ?? 0);
            $paid     = (int) ($counts->paid_count ?? 0);

            return [
                [
                    'from' => 'Draft', 'to' => 'Godown Ready',
                    'rate' => $draft > 0 ? round(($godown / $draft) * 100, 1) : 0,
                    'from_count' => $draft, 'to_count' => $godown,
                ],
                [
                    'from' => 'Godown Ready', 'to' => 'Delivered',
                    'rate' => $godown > 0 ? round(($delivered / $godown) * 100, 1) : 0,
                    'from_count' => $godown, 'to_count' => $delivered,
                ],
                [
                    'from' => 'Delivered', 'to' => 'Paid',
                    'rate' => $delivered > 0 ? round(($paid / $delivered) * 100, 1) : 0,
                    'from_count' => $delivered, 'to_count' => $paid,
                ],
                [
                    'from' => 'Draft', 'to' => 'Paid (Overall)',
                    'rate' => $draft > 0 ? round(($paid / $draft) * 100, 1) : 0,
                    'from_count' => $draft, 'to_count' => $paid,
                ],
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Pipeline trend: monthly open pipeline vs closed won for last 6 months.
     */
    private function getPipelineTrend(?int $branchId): array
    {
        try {
            $series = [];
            for ($m = 5; $m >= 0; $m--) {
                $monthStart = now()->subMonths($m)->startOfMonth()->toDateString();
                $monthEnd = now()->subMonths($m)->endOfMonth()->toDateString();
                $monthLabel = now()->subMonths($m)->format('M Y');

                $branchFilter = $branchId ? "AND branch_id = ?" : "";
                $params = [$monthStart, $monthEnd];
                if ($branchId) $params[] = $branchId;

                // Open pipeline at end of month
                $open = DB::selectOne("
                    SELECT COALESCE(SUM(total_amount), 0) AS val
                    FROM sales_invoices
                    WHERE invoice_date <= ?
                        AND is_reversed = false AND deleted_at IS NULL
                        AND status NOT IN ('cancelled')
                        AND NOT is_challan_issued
                        $branchFilter
                ", $params);

                // Closed won in that month
                $closed = DB::selectOne("
                    SELECT COALESCE(SUM(total_amount), 0) AS val
                    FROM sales_invoices
                    WHERE invoice_date BETWEEN ? AND ?
                        AND is_reversed = false AND deleted_at IS NULL
                        AND is_challan_issued AND due_amount <= 0
                        $branchFilter
                ", $params);

                $series[] = [
                    'month' => $monthLabel,
                    'open_pipeline' => (float) ($open->val ?? 0),
                    'closed_won' => (float) ($closed->val ?? 0),
                ];
            }
            return $series;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Salesman performance: pipeline ownership by salesman.
     */
    private function getSalesmanPerformance(?int $branchId, \Carbon\Carbon $from, \Carbon\Carbon $to): array
    {
        try {
            $baseWhere = "si.invoice_date BETWEEN ? AND ? AND si.is_reversed = false AND si.deleted_at IS NULL";
            $params = [$from->toDateString(), $to->toDateString()];
            $branchFilter = $branchId ? "AND si.branch_id = ?" : "";
            if ($branchId) $params[] = $branchId;

            $rows = DB::select("
                SELECT
                    e.name AS salesman_name,
                    SUM(CASE WHEN NOT si.is_challan_issued THEN 1 ELSE 0 END) AS open_count,
                    SUM(CASE WHEN NOT si.is_challan_issued THEN si.total_amount ELSE 0 END) AS open_value,
                    SUM(CASE WHEN si.is_challan_issued AND si.due_amount <= 0 THEN 1 ELSE 0 END) AS closed_count,
                    SUM(CASE WHEN si.is_challan_issued AND si.due_amount <= 0 THEN si.total_amount ELSE 0 END) AS closed_value,
                    COUNT(*) AS total_count,
                    SUM(si.total_amount) AS total_value
                FROM sales_invoices si
                LEFT JOIN employees e ON e.id = si.salesman_id
                WHERE $baseWhere $branchFilter
                    AND si.status NOT IN ('cancelled')
                GROUP BY e.name
                ORDER BY total_value DESC
                LIMIT 10
            ", $params);

            return array_map(fn($r) => [
                'name'         => $r->salesman_name ?? 'Unassigned',
                'open_count'   => (int) $r->open_count,
                'open_value'   => (float) $r->open_value,
                'closed_count' => (int) $r->closed_count,
                'closed_value' => (float) $r->closed_value,
                'total_count'  => (int) $r->total_count,
                'total_value'  => (float) $r->total_value,
                'win_rate'     => (int) $r->total_count > 0
                    ? round(($r->closed_count / $r->total_count) * 100, 1)
                    : 0,
            ], $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Open opportunities: active draft + godown invoices.
     */
    private function getOpenOpportunities(?int $branchId, \Carbon\Carbon $from, \Carbon\Carbon $to): array
    {
        try {
            $baseWhere = "si.invoice_date BETWEEN ? AND ? AND si.is_reversed = false AND si.deleted_at IS NULL";
            $params = [$from->toDateString(), $to->toDateString()];
            $branchFilter = $branchId ? "AND si.branch_id = ?" : "";
            if ($branchId) $params[] = $branchId;

            $rows = DB::select("
                SELECT
                    si.id, si.invoice_code, si.invoice_date, si.total_amount, si.due_amount,
                    si.status, si.is_godown_prepared, si.is_challan_issued,
                    c.customer_name,
                    e.name AS salesman_name,
                    b.branch_name,
                    CASE
                        WHEN si.status = 'draft' AND NOT si.is_godown_prepared THEN 'Draft'
                        WHEN si.is_godown_prepared AND NOT si.is_challan_issued THEN 'Godown'
                        WHEN si.is_challan_issued AND si.due_amount > 0 THEN 'Delivered'
                        ELSE 'Other'
                    END AS stage,
                    EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - si.created_at)) / 86400 AS days_open
                FROM sales_invoices si
                LEFT JOIN customers c ON c.id = si.customer_id
                LEFT JOIN employees e ON e.id = si.salesman_id
                LEFT JOIN branches b ON b.id = si.branch_id
                WHERE $baseWhere $branchFilter
                    AND si.status NOT IN ('cancelled')
                    AND NOT si.is_challan_issued OR (si.is_challan_issued AND si.due_amount > 0)
                ORDER BY si.total_amount DESC
                LIMIT 25
            ", $params);

            return array_map(fn($r) => [
                'id'             => (int) $r->id,
                'code'           => $r->invoice_code,
                'date'           => $r->invoice_date,
                'customer'       => $r->customer_name ?? '—',
                'salesman'       => $r->salesman_name ?? '—',
                'branch'         => $r->branch_name ?? '—',
                'stage'          => $r->stage,
                'amount'         => (float) $r->total_amount,
                'due'            => (float) $r->due_amount,
                'days_open'      => round((float) $r->days_open, 0),
            ], $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Revenue forecast: expected revenue in 30/60/90 days based on pipeline.
     */
    private function getForecast(?int $branchId, \Carbon\Carbon $from, \Carbon\Carbon $to): array
    {
        try {
            $baseWhere = "si.is_reversed = false AND si.deleted_at IS NULL AND si.status NOT IN ('cancelled')";
            $branchFilter = $branchId ? "AND si.branch_id = ?" : "";
            $params = $branchId ? [$branchId] : [];

            $pipeline = DB::selectOne("
                SELECT
                    COALESCE(SUM(CASE WHEN NOT si.is_godown_prepared THEN si.total_amount ELSE 0 END), 0) AS draft_value,
                    COALESCE(SUM(CASE WHEN si.is_godown_prepared AND NOT si.is_challan_issued THEN si.total_amount ELSE 0 END), 0) AS godown_value,
                    COALESCE(SUM(CASE WHEN si.is_challan_issued AND si.due_amount > 0 THEN si.due_amount ELSE 0 END), 0) AS delivered_due
                FROM sales_invoices si
                WHERE $baseWhere $branchFilter
            ", $params);

            $draftVal    = (float) ($pipeline->draft_value ?? 0);
            $godownVal   = (float) ($pipeline->godown_value ?? 0);
            $deliveredDue = (float) ($pipeline->delivered_due ?? 0);

            // Forecast based on stage probability and historical velocity
            return [
                '30_days'  => round($godownVal * 0.70 + $deliveredDue * 0.50, 0),
                '60_days'  => round($godownVal * 0.85 + $draftVal * 0.40 + $deliveredDue * 0.30, 0),
                '90_days'  => round($godownVal * 0.95 + $draftVal * 0.60 + $deliveredDue * 0.15, 0),
                'draft_value'    => $draftVal,
                'godown_value'   => $godownVal,
                'delivered_due'  => $deliveredDue,
            ];
        } catch (\Throwable $e) {
            return ['30_days' => 0, '60_days' => 0, '90_days' => 0,
                    'draft_value' => 0, 'godown_value' => 0, 'delivered_due' => 0];
        }
    }
}
