<?php
// app/services/Accounting/ReconciliationService.php — Phase 2/5 GL vs sub-ledger reconciliation

require_once __DIR__ . '/../../helpers/Helper.php';

class ReconciliationService extends Helper
{
    private float $tolerance;
    private const PARTY_MISMATCH_LIMIT = 20;

    /** @var array<string, string> GL nature → normal balance for sub-ledger comparison */
    private const NATURE_NORMAL = [
        'customer_receivable' => 'debit',
        'supplier_payable'    => 'credit',
        'employee_payable'    => 'debit',
        'inventory'           => 'debit',
        'cogs'                => 'debit',
        'cash_bank'           => 'debit',
    ];

    public function __construct(?Database $db = null, ?float $tolerance = null)
    {
        parent::__construct($db);
        $this->tolerance = $tolerance ?? $this->defaultTolerance();
    }

    private function defaultTolerance(): float
    {
        if (defined('GL_RECONCILIATION_TOLERANCE')) {
            return max(0.0001, (float)GL_RECONCILIATION_TOLERANCE);
        }
        return 0.02;
    }

    /**
     * Phase 2 AR snapshot (backward compatible).
     */
    public function getSalesArReport(?int $branchId = null): array
    {
        $full = $this->runFullReport($branchId);
        $ar = $full['ar'];

        return [
            'branch_id'               => $ar['branch_id'],
            'branch_name'             => $ar['branch_name'],
            'customer_ledger_net'     => $ar['customer_ledger_net'],
            'gl_ar_net'               => $ar['gl_ar_net'],
            'ar_difference'           => $ar['difference'],
            'ar_within_tolerance'     => $ar['within_tolerance'],
            'ledger_mismatch_count'   => $ar['ledger_mismatch_count'],
            'ledger_mismatches'       => $ar['ledger_mismatches'],
            'null_branch_ledger_rows' => $ar['null_branch_ledger_rows'],
            'ar_ledger_ids'           => $ar['ar_ledger_ids'],
            'tolerance'               => $this->tolerance,
            'ran_at'                  => $full['ran_at'],
        ];
    }

    /**
     * Full reconciliation: AR, AP, employee, cash/bank, inventory, COGS tie-out.
     */
    public function runFullReport(?int $branchId = null, ?string $fromDate = null, ?string $toDate = null): array
    {
        $branchId = $branchId ?? self::sessionBranchId();
        $branchFilter = ($branchId > 0) ? $branchId : null;
        $fromDate = $fromDate ?: date('Y-m-01');
        $toDate = $toDate ?: date('Y-m-d');

        $ar = $this->buildArSection($branchFilter);
        $ap = $this->buildApSection($branchFilter);
        $employee = $this->buildEmployeeSection($branchFilter);
        $cashBank = $this->buildCashBankSection($branchFilter);
        $inventory = $this->buildInventorySection($branchFilter);
        $cogs = $this->buildCogsSection($branchFilter, $fromDate, $toDate);

        $issues = [];
        if (!$ar['within_tolerance']) {
            $issues[] = 'AR sub-ledger vs GL difference ' . number_format($ar['difference'], 2);
        }
        if ($ar['ledger_mismatch_count'] > 0) {
            $issues[] = $ar['ledger_mismatch_count'] . ' customer ledger balance mismatch(es)';
        }
        if (!$ap['within_tolerance']) {
            $issues[] = 'AP sub-ledger vs GL difference ' . number_format($ap['difference'], 2);
        }
        if ($ap['ledger_mismatch_count'] > 0) {
            $issues[] = $ap['ledger_mismatch_count'] . ' supplier ledger balance mismatch(es)';
        }
        if (!$employee['within_tolerance']) {
            $issues[] = 'Employee sub-ledger vs GL difference ' . number_format($employee['difference'], 2);
        }
        if ($employee['ledger_mismatch_count'] > 0) {
            $issues[] = $employee['ledger_mismatch_count'] . ' employee ledger balance mismatch(es)';
        }
        if (!$cashBank['within_tolerance']) {
            $issues[] = 'Cash/bank register vs GL difference ' . number_format($cashBank['difference'], 2);
        }
        if ($cashBank['mapping_mismatch_count'] > 0) {
            $issues[] = $cashBank['mapping_mismatch_count'] . ' bank GL mapping mismatch(es)';
        }
        if (!$inventory['within_tolerance']) {
            $issues[] = 'Inventory GL vs stock valuation difference ' . number_format($inventory['difference'], 2);
        }
        if (!$cogs['within_tolerance']) {
            $issues[] = 'COGS GL vs stock OUT difference ' . number_format($cogs['difference'], 2) . ' (period)';
        }

        return [
            'branch_id'   => $branchFilter,
            'branch_name' => $this->resolveBranchName($branchFilter),
            'from_date'   => $fromDate,
            'to_date'     => $toDate,
            'tolerance'   => $this->tolerance,
            'ran_at'      => date('Y-m-d H:i:s'),
            'ar'          => $ar,
            'ap'          => $ap,
            'employee'    => $employee,
            'cash_bank'   => $cashBank,
            'inventory'   => $inventory,
            'cogs'        => $cogs,
            'has_issues'  => $issues !== [],
            'issues'      => $issues,
        ];
    }

    private function buildArSection(?int $branchId): array
    {
        $customerNet = $this->sumCustomerLedgerNetBalances($branchId);
        $glArNet = $this->sumGlArControlBalance($branchId);
        $diff = round($customerNet - $glArNet, 2);
        $mismatches = $this->getCustomerLedgerBalanceMismatches(
            $this->tolerance,
            $branchId,
            self::PARTY_MISMATCH_LIMIT
        );

        return [
            'branch_id'               => $branchId,
            'branch_name'             => $this->resolveBranchName($branchId),
            'customer_ledger_net'     => round($customerNet, 2),
            'gl_ar_net'               => round($glArNet, 2),
            'difference'              => $diff,
            'within_tolerance'        => abs($diff) <= $this->tolerance,
            'ledger_mismatch_count'   => count($mismatches),
            'ledger_mismatches'       => $mismatches,
            'null_branch_ledger_rows' => $this->countNullBranchLedgerRows($branchId),
            'ar_ledger_ids'           => $this->getLedgerIdsByNature('customer_receivable'),
        ];
    }

    private function buildApSection(?int $branchId): array
    {
        $supplierNet = $this->sumSupplierLedgerNetBalances($branchId);
        $glApNet = $this->sumGlControlByNature('supplier_payable', $branchId);
        $diff = round($supplierNet - $glApNet, 2);
        $mismatches = $this->getSupplierLedgerBalanceMismatches(
            $this->tolerance,
            $branchId,
            self::PARTY_MISMATCH_LIMIT
        );

        return [
            'branch_id'             => $branchId,
            'supplier_ledger_net'   => round($supplierNet, 2),
            'gl_ap_net'             => round($glApNet, 2),
            'difference'            => $diff,
            'within_tolerance'      => abs($diff) <= $this->tolerance,
            'ledger_mismatch_count' => count($mismatches),
            'ledger_mismatches'     => $mismatches,
            'ap_ledger_ids'         => $this->getLedgerIdsByNature('supplier_payable'),
        ];
    }

    private function buildEmployeeSection(?int $branchId): array
    {
        $employeeNet = $this->sumEmployeeLedgerNetBalances($branchId);
        $glEmployeeNet = $this->sumGlControlByNature('employee_payable', $branchId);
        $diff = round($employeeNet - $glEmployeeNet, 2);
        $mismatches = $this->getEmployeeLedgerBalanceMismatches(
            $this->tolerance,
            $branchId,
            self::PARTY_MISMATCH_LIMIT
        );

        return [
            'branch_id'             => $branchId,
            'employee_ledger_net'   => round($employeeNet, 2),
            'gl_employee_net'       => round($glEmployeeNet, 2),
            'difference'            => $diff,
            'within_tolerance'      => abs($diff) <= $this->tolerance,
            'ledger_mismatch_count' => count($mismatches),
            'ledger_mismatches'     => $mismatches,
            'employee_ledger_ids'   => $this->getLedgerIdsByNature('employee_payable'),
            'note'                  => 'Positive sub-ledger balance = employee owes company (Dr employee payable).',
        ];
    }

    private function buildCashBankSection(?int $branchId): array
    {
        $banksTotal = $this->sumActiveBankBalances();
        $glCashNet = $this->sumGlControlByNature('cash_bank', $branchId);
        $branchScoped = $branchId !== null && $branchId > 0;
        $mappingMismatches = $this->getBankMappingMismatches(self::PARTY_MISMATCH_LIMIT);

        $diff = $branchScoped ? 0.0 : round($banksTotal - $glCashNet, 2);
        $withinTolerance = $branchScoped
            ? ($mappingMismatches === [])
            : (abs($diff) <= $this->tolerance && $mappingMismatches === []);

        return [
            'branch_id'               => $branchId,
            'banks_total_balance'     => round($banksTotal, 2),
            'gl_cash_bank_net'        => round($glCashNet, 2),
            'difference'              => $diff,
            'within_tolerance'        => $withinTolerance,
            'mapping_mismatch_count'    => count($mappingMismatches),
            'mapping_mismatches'      => $mappingMismatches,
            'cash_bank_ledger_ids'    => $this->getLedgerIdsByNature('cash_bank'),
            'branch_scoped_note'      => $branchScoped
                ? 'Bank register is company-wide; GL cash_bank is branch-scoped — compare totals on combined (all branches) report.'
                : null,
        ];
    }

    private function buildInventorySection(?int $branchId): array
    {
        $stockValue = $this->sumWarehouseStockValue($branchId);
        $glInventory = $this->sumGlControlByNature('inventory', $branchId);
        $diff = round($stockValue - $glInventory, 2);
        $ledgerIds = $this->getLedgerIdsByNature('inventory');

        return [
            'warehouse_stock_value' => round($stockValue, 2),
            'gl_inventory_net'      => round($glInventory, 2),
            'difference'            => $diff,
            'within_tolerance'      => abs($diff) <= $this->tolerance,
            'inventory_ledger_ids'  => $ledgerIds,
            'note'                  => 'GL inventory is cumulative balance; stock is current on-hand at avg cost.',
        ];
    }

    private function buildCogsSection(?int $branchId, string $fromDate, string $toDate): array
    {
        $stockCogs = $this->sumChallanStockCogs($branchId, $fromDate, $toDate);
        $glCogs = $this->sumChallanGlCogs($branchId, $fromDate, $toDate);
        $diff = round($stockCogs - $glCogs, 2);

        return [
            'from_date'          => $fromDate,
            'to_date'            => $toDate,
            'stock_cogs_amount'  => round($stockCogs, 2),
            'gl_cogs_amount'     => round($glCogs, 2),
            'difference'         => $diff,
            'within_tolerance'   => abs($diff) <= $this->tolerance,
            'timing_note'        => 'Stock uses challan_date; GL uses journal entry_date. Small drift is normal across month-end.',
        ];
    }

    private function sumWarehouseStockValue(?int $branchId): float
    {
        $branchSql = '';
        if ($branchId !== null && $branchId > 0) {
            $branchSql = ' AND w.branch_id = ' . (int)$branchId;
        }

        $this->db->query("
            SELECT COALESCE(SUM(ws.qty * ws.avg_cost), 0) AS val
            FROM warehouse_stock ws
            INNER JOIN warehouses w ON w.id = ws.warehouse_id
            WHERE w.is_active = 1 {$branchSql}
        ");

        return (float)($this->db->single()['val'] ?? 0);
    }

    private function sumGlControlByNature(string $nature, ?int $branchId): float
    {
        $ledgerIds = $this->getLedgerIdsByNature($nature);
        if ($ledgerIds === []) {
            return 0.0;
        }

        $idList = implode(',', array_map('intval', $ledgerIds));
        $branchSql = '';
        if ($branchId !== null && $branchId > 0) {
            $branchSql = ' AND je.branch_id = ' . (int)$branchId;
        }

        $this->db->query("
            SELECT
                COALESCE(SUM(jl.debit), 0) AS total_debit,
                COALESCE(SUM(jl.credit), 0) AS total_credit
            FROM journal_lines jl
            INNER JOIN journal_entries je ON je.id = jl.journal_entry_id
            WHERE jl.ledger_id IN ({$idList})
              AND COALESCE(je.is_reversed, 0) = 0
              {$branchSql}
        ");
        $row = $this->db->single() ?: [];
        $debit = (float)($row['total_debit'] ?? 0);
        $credit = (float)($row['total_credit'] ?? 0);
        $normal = self::NATURE_NORMAL[$nature] ?? 'debit';

        return $normal === 'credit' ? ($credit - $debit) : ($debit - $credit);
    }

    /** @deprecated use sumGlControlByNature */
    private function sumGlLedgerNetByNature(string $nature, ?int $branchId): float
    {
        return $this->sumGlControlByNature($nature, $branchId);
    }

    private function sumChallanStockCogs(?int $branchId, string $fromDate, string $toDate): float
    {
        $branchSql = '';
        if ($branchId !== null && $branchId > 0) {
            $branchSql = ' AND si.branch_id = ' . (int)$branchId;
        }

        $this->db->query("
            SELECT COALESCE(SUM(ABS(st.qty) * st.rate), 0) AS amt
            FROM stock_transactions st
            INNER JOIN sales_challans sc ON st.reference_type = 'sales_challan'
                AND st.reference_id = sc.id
            INNER JOIN sales_invoices si ON si.id = sc.sales_invoice_id
            WHERE st.qty < -0.0001
              AND COALESCE(sc.is_reversed, 0) = 0
              AND sc.challan_date BETWEEN :from_d AND :to_d
              {$branchSql}
        ");
        $this->db->bind(':from_d', $fromDate);
        $this->db->bind(':to_d', $toDate);

        return (float)($this->db->single()['amt'] ?? 0);
    }

    private function sumChallanGlCogs(?int $branchId, string $fromDate, string $toDate): float
    {
        $cogsIds = $this->getLedgerIdsByNature('cogs');
        if ($cogsIds === []) {
            return 0.0;
        }

        $idList = implode(',', array_map('intval', $cogsIds));
        $branchSql = '';
        if ($branchId !== null && $branchId > 0) {
            $branchSql = ' AND je.branch_id = ' . (int)$branchId;
        }

        $this->db->query("
            SELECT COALESCE(SUM(jl.debit), 0) AS cogs_debit
            FROM journal_lines jl
            INNER JOIN journal_entries je ON je.id = jl.journal_entry_id
            WHERE jl.ledger_id IN ({$idList})
              AND je.reference_type = 'sales_challan'
              AND COALESCE(je.is_reversed, 0) = 0
              AND je.entry_date BETWEEN :from_d AND :to_d
              {$branchSql}
        ");
        $this->db->bind(':from_d', $fromDate);
        $this->db->bind(':to_d', $toDate);

        return (float)($this->db->single()['cogs_debit'] ?? 0);
    }

    private function resolveBranchName(?int $branchId): string
    {
        if ($branchId === null || $branchId <= 0) {
            return 'All branches';
        }

        $this->db->query('SELECT branch_name FROM branches WHERE id = :id LIMIT 1');
        $this->db->bind(':id', $branchId);
        $row = $this->db->single();

        return $row['branch_name'] ?? ('Branch #' . $branchId);
    }

    private function sumCustomerLedgerNetBalances(?int $branchId): float
    {
        $branchSql = '';
        if ($branchId !== null && $branchId > 0) {
            $branchSql = ' AND (cl.branch_id = ' . (int)$branchId . ' OR cl.branch_id IS NULL)';
        }

        $this->db->query("
            SELECT COALESCE(SUM(lb.last_balance), 0) AS total
            FROM (
                SELECT cl1.customer_id, cl1.running_balance AS last_balance
                FROM customer_ledger cl1
                INNER JOIN (
                    SELECT customer_id, MAX(id) AS max_id
                    FROM customer_ledger cl
                    WHERE COALESCE(cl.is_reversed, 0) = 0 {$branchSql}
                    GROUP BY customer_id
                ) latest ON cl1.id = latest.max_id
                WHERE COALESCE(cl1.is_reversed, 0) = 0
            ) lb
        ");

        return (float)($this->db->single()['total'] ?? 0);
    }

    private function sumSupplierLedgerNetBalances(?int $branchId): float
    {
        $branchSql = '';
        if ($branchId !== null && $branchId > 0) {
            $branchSql = ' AND (sl.branch_id = ' . (int)$branchId . ' OR sl.branch_id IS NULL)';
        }

        $this->db->query("
            SELECT COALESCE(SUM(lb.last_balance), 0) AS total
            FROM (
                SELECT sl1.supplier_id, sl1.running_balance AS last_balance
                FROM supplier_ledger sl1
                INNER JOIN (
                    SELECT supplier_id, MAX(id) AS max_id
                    FROM supplier_ledger sl
                    WHERE COALESCE(sl.is_reversed, 0) = 0 {$branchSql}
                    GROUP BY supplier_id
                ) latest ON sl1.id = latest.max_id
                WHERE COALESCE(sl1.is_reversed, 0) = 0
            ) lb
        ");

        return (float)($this->db->single()['total'] ?? 0);
    }

    private function sumEmployeeLedgerNetBalances(?int $branchId): float
    {
        $branchJoin = '';
        if ($branchId !== null && $branchId > 0) {
            $branchJoin = ' INNER JOIN employees e ON e.id = lb.employee_id AND e.branch_id = ' . (int)$branchId;
        }

        $this->db->query("
            SELECT COALESCE(SUM(lb.last_balance), 0) AS total
            FROM (
                SELECT el1.employee_id, el1.running_balance AS last_balance
                FROM employee_ledger el1
                INNER JOIN (
                    SELECT employee_id, MAX(id) AS max_id
                    FROM employee_ledger
                    WHERE COALESCE(is_reversed, 0) = 0
                    GROUP BY employee_id
                ) latest ON el1.id = latest.max_id
                WHERE COALESCE(el1.is_reversed, 0) = 0
            ) lb
            {$branchJoin}
        ");

        return (float)($this->db->single()['total'] ?? 0);
    }

    private function sumActiveBankBalances(): float
    {
        $this->db->query('
            SELECT COALESCE(SUM(COALESCE(balance, 0)), 0) AS total
            FROM banks
            WHERE COALESCE(is_active, 1) = 1
        ');

        return (float)($this->db->single()['total'] ?? 0);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getBankMappingMismatches(int $limit): array
    {
        $limit = max(1, min(100, $limit));

        try {
            $this->db->query("SHOW TABLES LIKE 'bank_ledger_mappings'");
            if (!$this->db->single()) {
                return [];
            }
        } catch (Exception $e) {
            return [];
        }

        $this->db->query("
            SELECT
                b.id AS bank_id,
                b.bank_name,
                COALESCE(b.balance, 0) AS bank_balance,
                blm.ledger_id AS mapped_ledger_id,
                l.ledger_name AS mapped_ledger_name,
                COALESCE(gl.net_bal, 0) AS gl_net,
                CASE
                    WHEN blm.ledger_id IS NULL THEN ABS(COALESCE(b.balance, 0))
                    ELSE ABS(COALESCE(b.balance, 0) - COALESCE(gl.net_bal, 0))
                END AS difference,
                CASE WHEN blm.ledger_id IS NULL THEN 1 ELSE 0 END AS is_unmapped
            FROM banks b
            LEFT JOIN bank_ledger_mappings blm ON blm.bank_id = b.id
            LEFT JOIN ledgers l ON l.id = blm.ledger_id
            LEFT JOIN (
                SELECT jl.ledger_id,
                       SUM(jl.debit) - SUM(jl.credit) AS net_bal
                FROM journal_lines jl
                INNER JOIN journal_entries je ON je.id = jl.journal_entry_id
                WHERE COALESCE(je.is_reversed, 0) = 0
                GROUP BY jl.ledger_id
            ) gl ON gl.ledger_id = blm.ledger_id
            WHERE COALESCE(b.is_active, 1) = 1
            HAVING difference > :tol OR is_unmapped = 1
            ORDER BY difference DESC
            LIMIT {$limit}
        ");
        $this->db->bind(':tol', $this->tolerance);

        return $this->db->resultSet() ?: [];
    }

    private function sumGlArControlBalance(?int $branchId): float
    {
        return $this->sumGlControlByNature('customer_receivable', $branchId);
    }

    /** @return int[] */
    private function getLedgerIdsByNature(string $nature): array
    {
        $this->db->query("
            SELECT id FROM ledgers
            WHERE ledger_nature = :nature AND is_active = 1
        ");
        $this->db->bind(':nature', $nature);
        $rows = $this->db->resultSet();
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int)$row['id'];
        }
        return $ids;
    }

    /** @return int[] @deprecated use getLedgerIdsByNature */
    private function getArLedgerIds(): array
    {
        return $this->getLedgerIdsByNature('customer_receivable');
    }

    private function countNullBranchLedgerRows(?int $branchId): int
    {
        $branchSql = '';
        if ($branchId !== null && $branchId > 0) {
            $branchSql = ' AND (branch_id = ' . (int)$branchId . ' OR branch_id IS NULL)';
        }

        $this->db->query("
            SELECT COUNT(*) AS c FROM customer_ledger
            WHERE branch_id IS NULL {$branchSql}
        ");

        return (int)($this->db->single()['c'] ?? 0);
    }

    /**
     * Run reconciliation for all active branches (or one branch / all combined).
     *
     * @return array<int, array>
     */
    public function runScheduledReconciliation(?int $branchFilter = null, ?string $fromDate = null, ?string $toDate = null): array
    {
        $fromDate = $fromDate ?: date('Y-m-01');
        $toDate = $toDate ?: date('Y-m-d');
        $reports = [];

        if ($branchFilter !== null && $branchFilter > 0) {
            $reports[] = $this->runFullReport($branchFilter, $fromDate, $toDate);
            $this->emitAlertsForReport($reports[0]);
            return $reports;
        }

        $reports[] = $this->runFullReport(null, $fromDate, $toDate);

        $this->db->query('SELECT id FROM branches WHERE COALESCE(is_active, 1) = 1 ORDER BY id ASC');
        foreach ($this->db->resultSet() as $row) {
            $bid = (int)$row['id'];
            if ($bid <= 0) {
                continue;
            }
            $reports[] = $this->runFullReport($bid, $fromDate, $toDate);
        }

        foreach ($reports as $report) {
            $this->emitAlertsForReport($report);
        }

        return $reports;
    }

    private function emitAlertsForReport(array $report): void
    {
        if (empty($report['has_issues'])) {
            return;
        }

        self::writeAlert('GL reconciliation issues', [
            'branch_id'   => $report['branch_id'] ?? null,
            'branch_name' => $report['branch_name'] ?? null,
            'issues'      => $report['issues'] ?? [],
            'from_date'   => $report['from_date'] ?? null,
            'to_date'     => $report['to_date'] ?? null,
        ]);
    }

    /**
     * Phase 5.4 — log + optional email when sales audit has hard failures.
     */
    public static function notifyAuditFailures(int $failCount, int $warnCount, array $context = []): void
    {
        if ($failCount <= 0) {
            return;
        }

        $payload = array_merge([
            'fail' => $failCount,
            'warn' => $warnCount,
        ], $context);

        self::writeAlert("Sales audit checklist: {$failCount} failure(s)", $payload);

        if (defined('RECON_ALERT_EMAIL') && RECON_ALERT_EMAIL !== '') {
            $to = RECON_ALERT_EMAIL;
            $subject = APP_NAME . ' — Sales audit failures (' . $failCount . ')';
            $body = "Sales audit reported {$failCount} failure(s) and {$warnCount} warning(s).\n\n"
                . json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            @mail($to, $subject, $body, 'Content-Type: text/plain; charset=UTF-8');
        }
    }

    /**
     * Write alert when audit/reconciliation finds failures (Phase 5.4).
     */
    public static function writeAlert(string $message, array $context = []): void
    {
        $path = self::alertLogPath();
        $logDir = dirname($path);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $line = json_encode([
            'timestamp' => date('Y-m-d H:i:s'),
            'message'   => $message,
            'context'   => $context,
        ], JSON_UNESCAPED_UNICODE);

        @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * Absolute path to the reconciliation / audit alert log.
     */
    public static function alertLogPath(): string
    {
        $logDir = defined('APP_ROOT') ? APP_ROOT . '/logs' : dirname(__DIR__, 3) . '/logs';

        return $logDir . '/reconciliation_alerts.log';
    }

    /**
     * Read the most recent alert log entries (newest first) for UI / CLI review.
     *
     * @return array<int, array{timestamp: string, message: string, context: array<string, mixed>}>
     */
    public static function readRecentAlerts(int $limit = 30): array
    {
        $limit = max(1, min(200, $limit));
        $path = self::alertLogPath();
        if (!is_readable($path)) {
            return [];
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || $lines === []) {
            return [];
        }

        $entries = [];
        for ($i = count($lines) - 1; $i >= 0 && count($entries) < $limit; $i--) {
            $decoded = json_decode($lines[$i], true);
            if (!is_array($decoded)) {
                continue;
            }

            $entries[] = [
                'timestamp' => (string)($decoded['timestamp'] ?? ''),
                'message'   => (string)($decoded['message'] ?? ''),
                'context'   => is_array($decoded['context'] ?? null) ? $decoded['context'] : [],
            ];
        }

        return $entries;
    }

    /**
     * Branch-scoped reports with issues — used for consolidated Telegram (skip combined "all branches" row).
     *
     * @param array<int, array<string, mixed>> $reports
     * @return array<int, array<string, mixed>>
     */
    public static function filterBranchIssueReports(array $reports): array
    {
        return array_values(array_filter($reports, static function (array $report): bool {
            if (empty($report['has_issues'])) {
                return false;
            }

            $branchId = $report['branch_id'] ?? null;

            return $branchId !== null && (int)$branchId > 0;
        }));
    }

    /** @return array<string, array{id: string, icon: string, label: string}> */
    public static function sectionDefinitions(): array
    {
        return [
            'ar'        => ['id' => 'recon-ar', 'icon' => 'fa-hand-holding-dollar', 'label' => 'Accounts receivable'],
            'ap'        => ['id' => 'recon-ap', 'icon' => 'fa-truck-field', 'label' => 'Accounts payable'],
            'employee'  => ['id' => 'recon-employee', 'icon' => 'fa-user-tie', 'label' => 'Employee payable'],
            'cash_bank' => ['id' => 'recon-cash', 'icon' => 'fa-building-columns', 'label' => 'Cash / bank'],
            'inventory' => ['id' => 'recon-inventory', 'icon' => 'fa-boxes-stacked', 'label' => 'Inventory'],
            'cogs'      => ['id' => 'recon-cogs', 'icon' => 'fa-chart-line', 'label' => 'COGS tie-out'],
        ];
    }

    /**
     * @return 'ok'|'warn'|'fail'
     */
    public static function sectionStatus(string $key, array $report): string
    {
        switch ($key) {
            case 'ar':
                $s = $report['ar'] ?? [];
                if (empty($s['within_tolerance'])) {
                    return 'fail';
                }
                if (((int)($s['ledger_mismatch_count'] ?? 0)) > 0 || ((int)($s['null_branch_ledger_rows'] ?? 0)) > 0) {
                    return 'warn';
                }
                return 'ok';
            case 'ap':
                $s = $report['ap'] ?? [];
                if (empty($s['within_tolerance'])) {
                    return 'fail';
                }
                if (((int)($s['ledger_mismatch_count'] ?? 0)) > 0) {
                    return 'warn';
                }
                return 'ok';
            case 'employee':
                $s = $report['employee'] ?? [];
                if (empty($s['within_tolerance'])) {
                    return 'fail';
                }
                if (((int)($s['ledger_mismatch_count'] ?? 0)) > 0) {
                    return 'warn';
                }
                return 'ok';
            case 'cash_bank':
                $s = $report['cash_bank'] ?? [];
                if (((int)($s['mapping_mismatch_count'] ?? 0)) > 0) {
                    return empty($s['within_tolerance']) ? 'fail' : 'warn';
                }
                return !empty($s['within_tolerance']) ? 'ok' : 'fail';
            case 'inventory':
                return !empty(($report['inventory'] ?? [])['within_tolerance']) ? 'ok' : 'fail';
            case 'cogs':
                return !empty(($report['cogs'] ?? [])['within_tolerance']) ? 'ok' : 'fail';
            default:
                return 'ok';
        }
    }

    public static function sectionStatusLabel(string $status): string
    {
        return match ($status) {
            'ok'   => 'Within tolerance',
            'warn' => 'Review details',
            'fail' => 'Out of tolerance',
            default => 'Unknown',
        };
    }

    /**
     * @return 'ok'|'warn'|'fail'
     */
    public static function overallStatus(array $report): string
    {
        $worst = 'ok';
        foreach (array_keys(self::sectionDefinitions()) as $key) {
            $st = self::sectionStatus($key, $report);
            if ($st === 'fail') {
                return 'fail';
            }
            if ($st === 'warn') {
                $worst = 'warn';
            }
        }
        return $worst;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function sectionSummaries(array $report): array
    {
        $out = [];
        foreach (self::sectionDefinitions() as $key => $def) {
            $status = self::sectionStatus($key, $report);
            $out[] = array_merge($def, [
                'key'          => $key,
                'status'       => $status,
                'status_label' => self::sectionStatusLabel($status),
            ]);
        }
        return $out;
    }
}