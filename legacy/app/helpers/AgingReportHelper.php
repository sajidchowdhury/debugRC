<?php
// app/helpers/AgingReportHelper.php — Phase 7D aging ↔ GL footnotes

require_once __DIR__ . '/../../core/Database.php';

class AgingReportHelper
{
    /**
     * @return array<string, mixed>
     */
    public static function buildApFootnote(float $agingTotal, string $asOfDate, ?int $branchId = null): array
    {
        return self::buildFootnote(
            'ap',
            'supplier_payable',
            'Supplier sub-ledger',
            'GL accounts payable control',
            BASE_URL . 'SupplierTransaction/index',
            $agingTotal,
            self::sumSupplierSubLedgerAsOf($asOfDate, $branchId),
            self::glControlAsOf('supplier_payable', $asOfDate, $branchId),
            $asOfDate,
            $branchId
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildArFootnote(float $agingTotal, string $asOfDate, ?int $branchId = null): array
    {
        return self::buildFootnote(
            'ar',
            'customer_receivable',
            'Customer sub-ledger',
            'GL accounts receivable control',
            BASE_URL . 'CustomerTransaction/index',
            $agingTotal,
            self::sumCustomerSubLedgerAsOf($asOfDate, $branchId),
            self::glControlAsOf('customer_receivable', $asOfDate, $branchId),
            $asOfDate,
            $branchId
        );
    }

    public static function sumSupplierSubLedgerAsOf(string $asOfDate, ?int $branchId = null): float
    {
        $db = new Database();
        $branchSql = self::branchSql('sl', $branchId);

        $db->query("
            SELECT COALESCE(SUM(t.balance), 0) AS total
            FROM (
                SELECT SUM(sl.debit - sl.credit) AS balance
                FROM supplier_ledger sl
                WHERE sl.transaction_date <= :as_of
                  AND COALESCE(sl.is_reversed, 0) = 0
                  {$branchSql}
                GROUP BY sl.supplier_id
                HAVING balance > 0.005
            ) t
        ");
        $db->bind(':as_of', $asOfDate);

        return round((float)($db->single()['total'] ?? 0), 2);
    }

    public static function sumCustomerSubLedgerAsOf(string $asOfDate, ?int $branchId = null): float
    {
        $db = new Database();
        $branchSql = self::branchSql('cl', $branchId);

        $db->query("
            SELECT COALESCE(SUM(t.balance), 0) AS total
            FROM (
                SELECT SUM(cl.debit - cl.credit) AS balance
                FROM customer_ledger cl
                WHERE cl.transaction_date <= :as_of
                  AND COALESCE(cl.is_reversed, 0) = 0
                  {$branchSql}
                GROUP BY cl.customer_id
                HAVING balance > 0.005
            ) t
        ");
        $db->bind(':as_of', $asOfDate);

        return round((float)($db->single()['total'] ?? 0), 2);
    }

    public static function glControlAsOf(string $nature, string $asOfDate, ?int $branchId = null): float
    {
        $db = new Database();
        $branchSql = '';
        if ($branchId !== null && $branchId > 0) {
            $branchSql = ' AND je.branch_id = ' . (int)$branchId;
        }

        $db->query("
            SELECT
                COALESCE(SUM(jl.debit), 0) AS total_debit,
                COALESCE(SUM(jl.credit), 0) AS total_credit
            FROM ledgers l
            INNER JOIN journal_lines jl ON jl.ledger_id = l.id
            INNER JOIN journal_entries je
                ON je.id = jl.journal_entry_id
               AND COALESCE(je.is_reversed, 0) = 0
               AND je.entry_date <= :as_of
               {$branchSql}
            WHERE l.is_active = 1
              AND l.ledger_nature = :nature
        ");
        $db->bind(':as_of', $asOfDate);
        $db->bind(':nature', $nature);
        $row = $db->single() ?: [];

        $debit = (float)($row['total_debit'] ?? 0);
        $credit = (float)($row['total_credit'] ?? 0);
        $normal = in_array($nature, ['supplier_payable', 'customer_receivable'], true)
            ? ($nature === 'supplier_payable' ? 'credit' : 'debit')
            : 'debit';

        if ($nature === 'supplier_payable') {
            return round($credit - $debit, 2);
        }

        return round($debit - $credit, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildFootnote(
        string $type,
        string $ledgerNature,
        string $subLedgerLabel,
        string $glLabel,
        string $moduleUrl,
        float $agingTotal,
        float $subLedgerTotal,
        float $glControlTotal,
        string $asOfDate,
        ?int $branchId
    ): array {
        $tolerance = defined('GL_RECONCILIATION_TOLERANCE') ? (float)GL_RECONCILIATION_TOLERANCE : 0.02;
        $agingVsSub = round($agingTotal - $subLedgerTotal, 2);
        $subVsGl = round($subLedgerTotal - $glControlTotal, 2);

        return [
            'type'                      => $type,
            'as_of_date'                => $asOfDate,
            'branch_id'                 => $branchId,
            'aging_total'               => round($agingTotal, 2),
            'sub_ledger_total'          => $subLedgerTotal,
            'gl_control_total'          => $glControlTotal,
            'aging_vs_sub_ledger_diff'  => $agingVsSub,
            'sub_ledger_vs_gl_diff'     => $subVsGl,
            'aging_matches_sub_ledger'  => abs($agingVsSub) <= $tolerance,
            'sub_ledger_matches_gl'     => abs($subVsGl) <= $tolerance,
            'within_tolerance'          => abs($agingVsSub) <= $tolerance && abs($subVsGl) <= $tolerance,
            'tolerance'                 => $tolerance,
            'sub_ledger_label'          => $subLedgerLabel,
            'gl_control_label'          => $glLabel,
            'ledger_nature'             => $ledgerNature,
            'module_url'                => $moduleUrl,
            'reconciliation_url'        => (defined('BASE_URL') ? BASE_URL : '/') . 'Reconciliation/index',
        ];
    }

    private static function branchSql(string $alias, ?int $branchId): string
    {
        if ($branchId === null || $branchId <= 0) {
            return '';
        }

        return ' AND (' . $alias . '.branch_id = ' . (int)$branchId
            . ' OR ' . $alias . '.branch_id IS NULL)';
    }
}
