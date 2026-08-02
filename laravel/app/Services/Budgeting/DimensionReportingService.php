<?php

namespace App\Services\Budgeting;

use App\Models\Dimension;
use App\Models\DimensionValue;
use App\Models\Ledger;
use Illuminate\Support\Facades\DB;

/**
 * DimensionReportingService — Phase 6: Segment Reporting
 *
 * Enables segment P&L and segment Balance Sheet by dimension value.
 * Every journal line can be tagged with a dimension_value_id, which
 * enables reporting by department, project, location, etc.
 *
 * Inspired by SAP B1's dimension system (up to 5 user-defined dimensions).
 */
class DimensionReportingService
{
    /**
     * Get segment P&L for a specific dimension value.
     *
     * Returns revenue, COGS, operating expenses, and net income
     * filtered by the given dimension_value_id.
     *
     * @param int    $dimensionValueId
     * @param string $fromDate  Y-m-d
     * @param string $toDate    Y-m-d
     * @param int|null $branchId
     * @return array
     */
    public function segmentProfitAndLoss(int $dimensionValueId, string $fromDate, string $toDate, ?int $branchId = null): array
    {
        $dimValue = DimensionValue::with('dimension')->findOrFail($dimensionValueId);

        // Revenue natures (credit normal)
        $revenueNatures = ['sales_revenue', 'transport_revenue', 'other_income', 'inventory_surplus'];
        // Contra-revenue (debit normal, reduce revenue)
        $contraRevenueNatures = ['sales_return', 'sales_discount'];
        // COGS
        $cogsNatures = ['cogs'];
        // Operating expenses
        $opexNatures = ['operating_expense', 'salary_expense', 'finance_cost', 'inventory_shrinkage', 'damage_loss'];

        $revenue  = $this->getDimensionNetByNatures($dimensionValueId, $revenueNatures, $fromDate, $toDate, $branchId);
        $contra   = $this->getDimensionNetByNatures($dimensionValueId, $contraRevenueNatures, $fromDate, $toDate, $branchId);
        $cogs     = $this->getDimensionNetByNatures($dimensionValueId, $cogsNatures, $fromDate, $toDate, $branchId);
        $opex     = $this->getDimensionNetByNatures($dimensionValueId, $opexNatures, $fromDate, $toDate, $branchId);

        $netRevenue = $revenue - $contra;
        $grossProfit = $netRevenue - $cogs;
        $grossMargin = $netRevenue > 0 ? round(($grossProfit / $netRevenue) * 100, 1) : 0;
        $operatingIncome = $grossProfit - $opex;
        $netMargin = $netRevenue > 0 ? round(($operatingIncome / $netRevenue) * 100, 1) : 0;

        return [
            'dimension_value' => $dimValue,
            'dimension'       => $dimValue->dimension,
            'period'          => ['from' => $fromDate, 'to' => $toDate],
            'revenue'         => $revenue,
            'contra_revenue'  => $contra,
            'net_revenue'     => $netRevenue,
            'cogs'            => $cogs,
            'gross_profit'    => $grossProfit,
            'gross_margin'    => $grossMargin,
            'operating_expense' => $opex,
            'operating_income'  => $operatingIncome,
            'net_margin'      => $netMargin,
        ];
    }

    /**
     * Get segment Balance Sheet for a specific dimension value.
     *
     * Returns assets, liabilities, and equity balances
     * filtered by the given dimension_value_id.
     *
     * @param int    $dimensionValueId
     * @param string $asOfDate  Y-m-d
     * @param int|null $branchId
     * @return array
     */
    public function segmentBalanceSheet(int $dimensionValueId, string $asOfDate, ?int $branchId = null): array
    {
        $dimValue = DimensionValue::with('dimension')->findOrFail($dimensionValueId);

        $assets     = $this->getDimensionBalanceByType($dimensionValueId, 'Asset', $asOfDate, $branchId);
        $liabilities = $this->getDimensionBalanceByType($dimensionValueId, 'Liability', $asOfDate, $branchId);
        $equity     = $this->getDimensionBalanceByType($dimensionValueId, 'Equity', $asOfDate, $branchId);

        return [
            'dimension_value' => $dimValue,
            'dimension'       => $dimValue->dimension,
            'as_of_date'      => $asOfDate,
            'assets'          => $assets,
            'liabilities'     => $liabilities,
            'equity'          => $equity,
            'total_assets'    => $assets,
            'total_liabilities_equity' => $liabilities + $equity,
        ];
    }

    /**
     * Get a comparison report across all values of a dimension.
     *
     * E.g. P&L comparison across all departments.
     *
     * @param int    $dimensionId
     * @param string $fromDate
     * @param string $toDate
     * @param int|null $branchId
     * @return array
     */
    public function dimensionComparison(int $dimensionId, string $fromDate, string $toDate, ?int $branchId = null): array
    {
        $dimension = Dimension::with('values')->findOrFail($dimensionId);

        $results = [];
        foreach ($dimension->values as $dimValue) {
            if (!$dimValue->is_active) {
                continue;
            }
            $results[] = $this->segmentProfitAndLoss($dimValue->id, $fromDate, $toDate, $branchId);
        }

        return [
            'dimension' => $dimension,
            'period'    => ['from' => $fromDate, 'to' => $toDate],
            'segments'  => $results,
        ];
    }

    /**
     * Get the dimension usage summary — how many journal lines
     * are tagged with each dimension value.
     */
    public function getDimensionUsageSummary(int $dimensionId, string $fromDate, string $toDate): array
    {
        $dimension = Dimension::with('values')->findOrFail($dimensionId);

        $summary = [];
        foreach ($dimension->values as $dimValue) {
            $lineCount = DB::table('journal_lines')
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_lines.dimension_value_id', $dimValue->id)
                ->whereBetween('journal_entries.entry_date', [$fromDate, $toDate])
                ->where('journal_entries.is_reversed', false)
                ->count();

            $totalDebit = DB::table('journal_lines')
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_lines.dimension_value_id', $dimValue->id)
                ->whereBetween('journal_entries.entry_date', [$fromDate, $toDate])
                ->where('journal_entries.is_reversed', false)
                ->sum('journal_lines.debit');

            $summary[] = [
                'id'          => $dimValue->id,
                'code'        => $dimValue->code,
                'name'        => $dimValue->name,
                'line_count'  => $lineCount,
                'total_debit' => (float) $totalDebit,
            ];
        }

        return [
            'dimension' => $dimension,
            'period'    => ['from' => $fromDate, 'to' => $toDate],
            'summary'  => $summary,
        ];
    }

    // ── Private helpers ─────────────────────────────────────────────

    /**
     * Get the net amount for a set of ledger natures filtered by dimension value.
     *
     * For credit-normal natures: net = credit - debit
     * For debit-normal natures:  net = debit - credit
     */
    private function getDimensionNetByNatures(int $dimensionValueId, array $natures, string $fromDate, string $toDate, ?int $branchId = null): float
    {
        $ledgerIds = Ledger::whereIn('ledger_nature', $natures)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->toArray();

        if (empty($ledgerIds)) {
            return 0;
        }

        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('ledgers', 'ledgers.id', '=', 'journal_lines.ledger_id')
            ->whereIn('journal_lines.ledger_id', $ledgerIds)
            ->where('journal_lines.dimension_value_id', $dimensionValueId)
            ->whereBetween('journal_entries.entry_date', [$fromDate, $toDate])
            ->where('journal_entries.is_reversed', false)
            ->whereNull('ledgers.deleted_at');

        if ($branchId !== null) {
            $query->where('journal_entries.branch_id', $branchId);
        }

        $result = $query->selectRaw("
            SUM(
                CASE ledgers.normal_balance
                    WHEN 'credit' THEN journal_lines.credit - journal_lines.debit
                    WHEN 'debit'  THEN journal_lines.debit - journal_lines.credit
                END
            ) AS net_amount
        ")->first();

        return (float) ($result->net_amount ?? 0);
    }

    /**
     * Get the balance for a given account type filtered by dimension value.
     *
     * For Assets (debit normal):  balance = debit - credit
     * For Liabilities/Equity (credit normal): balance = credit - debit
     */
    private function getDimensionBalanceByType(int $dimensionValueId, string $accountType, string $asOfDate, ?int $branchId = null): float
    {
        $ledgerIds = Ledger::where('account_type', $accountType)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->toArray();

        if (empty($ledgerIds)) {
            return 0;
        }

        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('ledgers', 'ledgers.id', '=', 'journal_lines.ledger_id')
            ->whereIn('journal_lines.ledger_id', $ledgerIds)
            ->where('journal_lines.dimension_value_id', $dimensionValueId)
            ->where('journal_entries.entry_date', '<=', $asOfDate)
            ->where('journal_entries.is_reversed', false)
            ->whereNull('ledgers.deleted_at');

        if ($branchId !== null) {
            $query->where('journal_entries.branch_id', $branchId);
        }

        $result = $query->selectRaw("
            SUM(
                CASE ledgers.normal_balance
                    WHEN 'debit'  THEN journal_lines.debit - journal_lines.credit
                    WHEN 'credit' THEN journal_lines.credit - journal_lines.debit
                END
            ) AS balance
        ")->first();

        return (float) ($result->balance ?? 0);
    }
}
