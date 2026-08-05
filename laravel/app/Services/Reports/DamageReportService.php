<?php

namespace App\Services\Reports;

use App\Facades\CsvExporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Models\DamageInvoice;

/**
 * Damage Report Service — Phase 6 (Damage plan).
 *
 * Provides multi-dimensional aggregations of damage/loss data:
 *   - monthlyTrend()   — month-by-month cost + count, split by type
 *   - byWarehouse()    — warehouse-wise cost breakdown
 *   - byCategory()     — damage_type breakdown (real_damage / missing / theft …)
 *   - byEmployee()     — accountable-employee ranking (liable vs recovered)
 *   - topProducts()    — most-damaged products by cost & qty
 *   - byStatus()       — status distribution (draft / submitted / approved / confirmed …)
 *   - kpi()            — period totals + month-over-month growth %
 *   - getDetailLines() — line-level damage rows for the detail table
 *   - summarize()      — aggregate totals for a set of detail lines
 *   - exportCsv()      — UTF-8 BOM CSV stream
 *
 * Branch scoping: RLS on damage_invoices automatically filters non-admin
 * users to their own branch. An explicit branchId param is applied as
 * defense-in-depth (admin-selectable single-branch view). damage_invoice_items
 * and damage_attachments inherit scoping via the damage_invoices join.
 *
 * Only CONFIRMED damages (status='confirmed', is_reversed=false) contribute
 * to cost aggregations — drafts/submitted/approved have not yet posted stock
 * or GL. Cancelled/rejected are excluded entirely. The byStatus() method is
 * the exception: it counts ALL statuses for the worklist view.
 */
class DamageReportService
{
    /**
     * Base query builder for confirmed damage invoices in a period.
     *
     * @param string $dateFrom  Y-m-d
     * @param string $dateTo    Y-m-d
     * @param int|null $branchId  null = all branches (admin); int = single branch
     */
    protected function baseConfirmedQuery(string $dateFrom, string $dateTo, ?int $branchId = null)
    {
        $q = DB::table('damage_invoices as di')
            ->where('di.status', 'confirmed')
            ->where('di.is_reversed', false)
            ->whereNull('di.deleted_at')
            ->whereBetween('di.damage_date', [$dateFrom, $dateTo]);

        if ($branchId) {
            $q->where('di.branch_id', (int) $branchId);
        }

        return $q;
    }

    /**
     * KPI summary for a period: total cost, count, recovered, net loss,
     * plus month-over-month growth %.
     *
     * @return array{mtd_value:float, mtd_count:int, recovered:float, net_loss:float, prev_value:float, growth_pct:float}
     */
    public function kpi(string $dateFrom, string $dateTo, ?int $branchId = null): array
    {
        $row = $this->baseConfirmedQuery($dateFrom, $dateTo, $branchId)
            ->selectRaw('
                COALESCE(SUM(di.total_value), 0) AS total_value,
                COUNT(*) AS damage_count,
                COALESCE(SUM(di.recovery_amount), 0) AS recovered
            ')
            ->first();

        $totalValue = (float) ($row->total_value ?? 0);
        $count      = (int) ($row->damage_count ?? 0);
        $recovered  = (float) ($row->recovered ?? 0);

        // Previous-period comparison (same-length window ending the day before $dateFrom).
        $from = Carbon::parse($dateFrom);
        $to   = Carbon::parse($dateTo);
        $days = $from->diffInDays($to) + 1;
        $prevTo   = $from->copy()->subDay()->toDateString();
        $prevFrom = $from->copy()->subDays($days)->toDateString();

        $prevRow = $this->baseConfirmedQuery($prevFrom, $prevTo, $branchId)
            ->selectRaw('COALESCE(SUM(di.total_value), 0) AS total_value')
            ->first();
        $prevValue = (float) ($prevRow->total_value ?? 0);

        $growth = $prevValue > 0
            ? round((($totalValue - $prevValue) / $prevValue) * 100, 1)
            : 0.0;

        return [
            'mtd_value'   => round($totalValue, 2),
            'mtd_count'   => $count,
            'recovered'   => round($recovered, 2),
            'net_loss'    => round($totalValue - $recovered, 2),
            'prev_value'  => round($prevValue, 2),
            'growth_pct'  => $growth,
        ];
    }

    /**
     * Month-by-month cost + count, split by damage_type.
     *
     * @return array<int, object{month:string, damage_type:string, total_cost:float, damage_count:int, recovered:float}>
     */
    public function monthlyTrend(string $dateFrom, string $dateTo, ?int $branchId = null): array
    {
        return $this->baseConfirmedQuery($dateFrom, $dateTo, $branchId)
            ->selectRaw("
                TO_CHAR(di.damage_date, 'YYYY-MM') AS month,
                di.damage_type,
                COALESCE(SUM(di.total_value), 0) AS total_cost,
                COUNT(*) AS damage_count,
                COALESCE(SUM(di.recovery_amount), 0) AS recovered
            ")
            ->groupByRaw("TO_CHAR(di.damage_date, 'YYYY-MM'), di.damage_type")
            ->orderByRaw("month ASC, di.damage_type ASC")
            ->get()
            ->all();
    }

    /**
     * Warehouse-wise cost breakdown (joins warehouses + branches).
     *
     * @return array<int, object{warehouse_id:int, warehouse_name:string, warehouse_code:string, branch_name:string, total_cost:float, damage_count:int, recovered:float}>
     */
    public function byWarehouse(string $dateFrom, string $dateTo, ?int $branchId = null): array
    {
        return $this->baseConfirmedQuery($dateFrom, $dateTo, $branchId)
            ->leftJoin('warehouses as w', 'w.id', '=', 'di.warehouse_id')
            ->leftJoin('branches as b', 'b.id', '=', 'di.branch_id')
            ->selectRaw("
                w.id AS warehouse_id,
                COALESCE(w.warehouse_name, '(unknown)') AS warehouse_name,
                COALESCE(w.warehouse_code, '') AS warehouse_code,
                COALESCE(b.branch_name, '(unknown)') AS branch_name,
                COALESCE(SUM(di.total_value), 0) AS total_cost,
                COUNT(*) AS damage_count,
                COALESCE(SUM(di.recovery_amount), 0) AS recovered
            ")
            ->groupByRaw('w.id, w.warehouse_name, w.warehouse_code, b.branch_name')
            ->orderByRaw('total_cost DESC')
            ->get()
            ->all();
    }

    /**
     * Damage-type (category) breakdown.
     *
     * @return array<int, object{damage_type:string, label:string, total_cost:float, damage_count:int, recovered:float, net_loss:float}>
     */
    public function byCategory(string $dateFrom, string $dateTo, ?int $branchId = null): array
    {
        $rows = $this->baseConfirmedQuery($dateFrom, $dateTo, $branchId)
            ->selectRaw("
                di.damage_type,
                COALESCE(SUM(di.total_value), 0) AS total_cost,
                COUNT(*) AS damage_count,
                COALESCE(SUM(di.recovery_amount), 0) AS recovered
            ")
            ->groupBy('di.damage_type')
            ->orderByRaw('total_cost DESC')
            ->get()
            ->all();

        $labels = DamageInvoice::DAMAGE_TYPES;
        foreach ($rows as $r) {
            $r->label    = $labels[$r->damage_type] ?? ucfirst(str_replace('_', ' ', $r->damage_type));
            $r->net_loss = round((float) $r->total_cost - (float) $r->recovered, 2);
            $r->total_cost = round((float) $r->total_cost, 2);
            $r->recovered  = round((float) $r->recovered, 2);
        }

        return $rows;
    }

    /**
     * Accountable-employee ranking (liability vs recovery).
     *
     * @return array<int, object{employee_id:int, employee_name:string, employee_code:string, role:string, damage_count:int, total_liable:float, total_recovered:float, outstanding:float}>
     */
    public function byEmployee(string $dateFrom, string $dateTo, ?int $branchId = null): array
    {
        $rows = $this->baseConfirmedQuery($dateFrom, $dateTo, $branchId)
            ->leftJoin('employees as e', 'e.id', '=', 'di.accountable_employee_id')
            ->whereNotNull('di.accountable_employee_id')
            ->selectRaw("
                e.id AS employee_id,
                COALESCE(e.name, '(unknown)') AS employee_name,
                COALESCE(e.employee_code, '') AS employee_code,
                COALESCE(e.role, '') AS role,
                COUNT(*) AS damage_count,
                COALESCE(SUM(di.total_value), 0) AS total_liable,
                COALESCE(SUM(di.recovery_amount), 0) AS total_recovered
            ")
            ->groupByRaw('e.id, e.name, e.employee_code, e.role')
            ->orderByRaw('total_liable DESC')
            ->get()
            ->all();

        foreach ($rows as $r) {
            $r->total_liable   = round((float) $r->total_liable, 2);
            $r->total_recovered = round((float) $r->total_recovered, 2);
            $r->outstanding     = round($r->total_liable - $r->total_recovered, 2);
        }

        return $rows;
    }

    /**
     * Most-damaged products by cost (joins damage_invoice_items + products).
     *
     * @param int $limit  top-N (default 20)
     * @return array<int, object{product_id:int, product_name:string, product_code:string, damage_count:int, total_qty:float, total_cost:float}>
     */
    public function topProducts(string $dateFrom, string $dateTo, int $limit = 20, ?int $branchId = null): array
    {
        $q = DB::table('damage_invoice_items as dii')
            ->join('damage_invoices as di', 'di.id', '=', 'dii.damage_invoice_id')
            ->leftJoin('products as p', 'p.id', '=', 'dii.product_id')
            ->where('di.status', 'confirmed')
            ->where('di.is_reversed', false)
            ->whereNull('di.deleted_at')
            ->whereBetween('di.damage_date', [$dateFrom, $dateTo])
            ->selectRaw("
                p.id AS product_id,
                COALESCE(p.product_name, '(unknown)') AS product_name,
                COALESCE(p.product_code, '') AS product_code,
                COUNT(DISTINCT di.id) AS damage_count,
                COALESCE(SUM(dii.qty), 0) AS total_qty,
                COALESCE(SUM(dii.qty * dii.rate), 0) AS total_cost
            ")
            ->groupByRaw('p.id, p.product_name, p.product_code')
            ->orderByRaw('total_cost DESC')
            ->limit($limit);

        if ($branchId) {
            $q->where('di.branch_id', (int) $branchId);
        }

        $rows = $q->get()->all();
        foreach ($rows as $r) {
            $r->total_qty  = round((float) $r->total_qty, 4);
            $r->total_cost = round((float) $r->total_cost, 2);
        }

        return $rows;
    }

    /**
     * Status distribution (ALL statuses — for the worklist view).
     * Includes draft / submitted / approved / confirmed / cancelled / rejected.
     *
     * @return array<int, object{status:string, damage_count:int, total_value:float}>
     */
    public function byStatus(string $dateFrom, string $dateTo, ?int $branchId = null): array
    {
        $q = DB::table('damage_invoices as di')
            ->whereNull('di.deleted_at')
            ->whereBetween('di.damage_date', [$dateFrom, $dateTo])
            ->selectRaw("
                di.status,
                COUNT(*) AS damage_count,
                COALESCE(SUM(di.total_value), 0) AS total_value
            ")
            ->groupBy('di.status')
            ->orderByRaw("damage_count DESC");

        if ($branchId) {
            $q->where('di.branch_id', (int) $branchId);
        }

        $rows = $q->get()->all();
        foreach ($rows as $r) {
            $r->total_value = round((float) $r->total_value, 2);
        }

        return $rows;
    }

    /**
     * Line-level detail rows for the main report table.
     *
     * @param array{from?:string, to?:string, branch_id?:int, warehouse_id?:int, damage_type?:string, status?:string, accountable_employee_id?:int} $filters
     * @return array<int, object>
     */
    public function getDetailLines(array $filters = []): array
    {
        $from = $filters['from'] ?? now()->startOfMonth()->format('Y-m-d');
        $to   = $filters['to']   ?? now()->toDateString();

        $q = DB::table('damage_invoices as di')
            ->leftJoin('branches as b', 'b.id', '=', 'di.branch_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'di.warehouse_id')
            ->leftJoin('employees as e_acc', 'e_acc.id', '=', 'di.accountable_employee_id')
            ->leftJoin('employees as e_wit', 'e_wit.id', '=', 'di.witness_employee_id')
            ->leftJoin('users as u_app', 'u_app.id', '=', 'di.approved_by')
            ->leftJoin('employees as e_app', 'e_app.id', '=', 'u_app.employee_id')
            ->whereNull('di.deleted_at')
            ->whereBetween('di.damage_date', [$from, $to])
            ->select(
                'di.id',
                'di.damage_code',
                'di.damage_date',
                'di.damage_type',
                'di.status',
                'di.reason_code',
                'di.reason',
                'di.total_value',
                'di.recovery_amount',
                'di.is_reversed',
                'di.submitted_at',
                'di.approved_at',
                'b.branch_name',
                'w.warehouse_name',
                'w.warehouse_code',
                DB::raw('e_acc.name AS accountable_name'),
                DB::raw('e_acc.employee_code AS accountable_code'),
                DB::raw('e_wit.name AS witness_name'),
                DB::raw('e_wit.employee_code AS witness_code'),
                DB::raw('COALESCE(e_app.name, u_app.username) AS approver_name')
            );

        if (!empty($filters['branch_id'])) {
            $q->where('di.branch_id', (int) $filters['branch_id']);
        }
        if (!empty($filters['warehouse_id'])) {
            $q->where('di.warehouse_id', (int) $filters['warehouse_id']);
        }
        if (!empty($filters['damage_type'])) {
            $q->where('di.damage_type', $filters['damage_type']);
        }
        if (!empty($filters['status'])) {
            $q->where('di.status', $filters['status']);
        }
        if (!empty($filters['accountable_employee_id'])) {
            $q->where('di.accountable_employee_id', (int) $filters['accountable_employee_id']);
        }

        $q->orderByDesc('di.damage_date')->orderByDesc('di.id')->limit(500);

        return $q->get()->all();
    }

    /**
     * Aggregate totals for a set of detail lines.
     *
     * @param array<int, object> $rows
     * @return array{total_count:int, confirmed_count:int, confirmed_value:float, recovered:float, net_loss:float, awaiting_count:int, awaiting_value:float}
     */
    public function summarize(array $rows): array
    {
        $totalCount    = count($rows);
        $confirmedCount = 0;
        $confirmedValue = 0.0;
        $recovered     = 0.0;
        $awaitingCount = 0;
        $awaitingValue = 0.0;

        foreach ($rows as $r) {
            if ($r->status === 'confirmed' && !$r->is_reversed) {
                $confirmedCount++;
                $confirmedValue += (float) ($r->total_value ?? 0);
                $recovered      += (float) ($r->recovery_amount ?? 0);
            }
            if ($r->status === 'submitted') {
                $awaitingCount++;
                $awaitingValue += (float) ($r->total_value ?? 0);
            }
        }

        return [
            'total_count'     => $totalCount,
            'confirmed_count' => $confirmedCount,
            'confirmed_value' => round($confirmedValue, 2),
            'recovered'       => round($recovered, 2),
            'net_loss'        => round($confirmedValue - $recovered, 2),
            'awaiting_count'  => $awaitingCount,
            'awaiting_value'  => round($awaitingValue, 2),
        ];
    }

    /**
     * Stream a CSV of detail lines (Excel-friendly with UTF-8 BOM).
     *
     * REPORTS-AUDIT-4 (G-150 / csv-export.md G11): refactored to delegate
     * to CsvExporter::exportFromRows(). BOM + Content-Type + RFC 4180
     * escaping now handled by the canonical service. Column order and
     * column labels preserved exactly. Audit-log row is written by the
     * calling controller (ReportController::damageReportExport).
     *
     * @param array<int, object> $rows
     */
    public function exportCsv(array $rows): StreamedResponse
    {
        $headerRow = [
            'Damage Code', 'Date', 'Branch', 'Warehouse', 'Type', 'Status',
            'Reason Code', 'Reason', 'Total Value', 'Recovered',
            'Accountable', 'Witness', 'Approver', 'Submitted At', 'Approved At',
        ];

        $typeLabels = DamageInvoice::DAMAGE_TYPES;
        $rowGenerator = $this->buildDamageCsvRows($rows, $typeLabels);

        $filename = CsvExporter::filename('Damage_Report');

        return CsvExporter::exportFromRows($filename, $headerRow, $rowGenerator);
    }

    /**
     * Build the row generator for the damage-detail CSV export.
     *
     * Extracted as a private method so the lint checker can validate the
     * exportCsv() method body (the linter cannot parse `yield` inside an
     * inline closure expression).
     *
     * @param  array<int, object> $rows
     * @param  array<string,string> $typeLabels
     * @return \Generator<int, array<int,mixed>>
     */
    private function buildDamageCsvRows(array $rows, array $typeLabels): \Generator
    {
        foreach ($rows as $r) {
            yield [
                $r->damage_code ?? '',
                $r->damage_date ?? '',
                $r->branch_name ?? '',
                $r->warehouse_name ?? '',
                $typeLabels[$r->damage_type] ?? $r->damage_type ?? '',
                $r->status ?? '',
                $r->reason_code ?? '',
                $r->reason ?? '',
                $r->total_value ?? 0,
                $r->recovery_amount ?? 0,
                trim(($r->accountable_name ?? '') . ' ' . ($r->accountable_code ?? '')),
                trim(($r->witness_name ?? '') . ' ' . ($r->witness_code ?? '')),
                $r->approver_name ?? '',
                $r->submitted_at ?? '',
                $r->approved_at ?? '',
            ];
        }
    }
}
