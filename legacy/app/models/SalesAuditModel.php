<?php
// app/models/SalesAuditModel.php — full sales ecosystem audit (masters, invoice, godown, challan, returns, payments, GL, reports)

require_once '../core/Database.php';
require_once __DIR__ . '/../helpers/Helper.php';
require_once __DIR__ . '/../services/Accounting/ReconciliationService.php';

class SalesAuditModel extends Helper
{
    protected ?int $branchId;

    /** @var string[] */
    protected array $salesStockRefs = [
        'sales_challan',
        'sales_challan_reversal',
        'sales_return',
        'sales_return_reversal',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->branchId = self::sessionBranchId();
    }

    public function runHealthChecks(): array
    {
        $reconFull = (new ReconciliationService())->runFullReport($this->branchId ?: null);

        $repairedDispatches = $this->repairDispatchWarehousesFromChallanStock();
        $repairedInvoices   = $this->repairMissingInvoiceAccounting();
        $repairedChallans   = $this->repairMissingChallanCogsJournals();
        $repairedReturns    = $this->repairMissingSalesReturnJournals();
        $repairedPayments   = $this->repairMissingCustomerPaymentJournals();

        $sections = [
            $this->sectionModuleScope(),
            $this->sectionProducts(),
            $this->sectionCustomers(),
            $this->sectionWarehousesAndDispatch(),
            $this->sectionStockSsot(),
            $this->sectionSalesInvoice(),
            $this->sectionGodown(),
            $this->sectionChallan(),
            $this->sectionSalesReturn(),
            $this->sectionCustomerPayments(),
            $this->sectionGlJournalLinks(),
            $this->sectionLedger($reconFull),
            $this->sectionGlReconciliation($reconFull),
            $this->sectionReports(),
        ];

        $pass = $warn = $fail = $info = 0;
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                switch ($item['status']) {
                    case 'pass': $pass++; break;
                    case 'warn': $warn++; break;
                    case 'fail': $fail++; break;
                    default: $info++;
                }
            }
        }

        ReconciliationService::notifyAuditFailures($fail, $warn, [
            'branch_id' => $this->branchId,
            'ran_at'    => date('Y-m-d H:i:s'),
        ]);

        return [
            'sections'        => $sections,
            'summary'         => [
                'pass'  => $pass,
                'warn'  => $warn,
                'fail'  => $fail,
                'info'  => $info,
                'total' => $pass + $warn + $fail + $info,
            ],
            'reconciliation'       => $reconFull,
            'ran_at'               => date('Y-m-d H:i:s'),
            'branch_id'            => $this->branchId,
            'negative_stocks'      => $this->getNegativeStockRows(),
            'invalid_dispatches'    => $this->getInvalidPostGodownDispatchRows(),
            'repaired_dispatches'   => $repairedDispatches,
            'repaired_invoices'     => $repairedInvoices,
            'missing_journal_rows'   => $this->getInvoicesMissingJournalRows(),
            'missing_cogs_challans'  => $this->getChallansMissingCogsRows(),
            'repaired_challans'      => $repairedChallans,
            'repaired_returns'       => $repairedReturns,
            'repaired_payments'      => $repairedPayments,
            'ledger_mismatches'      => $this->getCustomerLedgerBalanceMismatches(0.02, $this->branchId),
        ];
    }

    /**
     * Link orphan return journals, backfill customer_ledger, post postSalesReturn from header + stock IN.
     *
     * @return array{linked: int, posted: int, ledgers_added: int, errors: string[]}
     */
    public function repairMissingSalesReturnJournals(): array
    {
        $result = ['linked' => 0, 'posted' => 0, 'ledgers_added' => 0, 'errors' => []];

        try {
            $branchSql = $this->branchId
                ? ' AND EXISTS (
                    SELECT 1 FROM sales_invoices si
                    WHERE si.id = sr.sales_invoice_id AND si.branch_id = ' . (int)$this->branchId . '
                )'
                : '';

            $this->db->query("
                UPDATE sales_returns sr
                INNER JOIN journal_entries je ON je.reference_type = 'sales_return'
                    AND je.reference_id = sr.id
                    AND COALESCE(je.is_reversed, 0) = 0
                SET sr.journal_entry_id = je.id
                WHERE sr.status = 'completed'
                  AND COALESCE(sr.is_reversed, 0) = 0
                  AND COALESCE(sr.journal_entry_id, 0) = 0
                  {$branchSql}
            ");
            $this->db->execute();
            $result['linked'] = $this->db->rowCount();

            $this->db->query("
                SELECT sr.id, sr.return_code, sr.return_date, sr.total_amount, sr.customer_id,
                       si.branch_id,
                       (
                           SELECT COALESCE(SUM(st.qty * st.rate), 0)
                           FROM stock_transactions st
                           WHERE st.reference_type = 'sales_return'
                             AND st.reference_id = sr.id
                             AND st.qty > 0.0001
                       ) AS cogs_amount
                FROM sales_returns sr
                INNER JOIN sales_invoices si ON si.id = sr.sales_invoice_id
                WHERE sr.status = 'completed'
                  AND COALESCE(sr.is_reversed, 0) = 0
                  AND COALESCE(sr.journal_entry_id, 0) = 0
                  AND sr.return_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
                  AND sr.total_amount > 0
                  {$branchSql}
                ORDER BY sr.id ASC
                LIMIT 50
            ");
            $returns = $this->db->resultSet() ?: [];

            require_once __DIR__ . '/../services/Accounting/JournalPostingService.php';
            $journalService = new JournalPostingService();

            foreach ($returns as $sr) {
                $returnId = (int)$sr['id'];
                try {
                    if (!$this->returnHasCustomerLedger($returnId)) {
                        $this->insertCustomerLedgerForReturn($sr);
                        $result['ledgers_added']++;
                    }

                    $revenueAmount = round((float)($sr['total_amount'] ?? 0), 2);
                    $cogsAmount    = round((float)($sr['cogs_amount'] ?? 0), 2);

                    if ($revenueAmount <= 0 && $cogsAmount <= 0) {
                        continue;
                    }

                    $journalResult = $journalService->postSalesReturn($returnId, [
                        'return_code'    => $sr['return_code'] ?? ('SR-' . $returnId),
                        'return_date'    => $sr['return_date'] ?? date('Y-m-d'),
                        'customer_id'    => (int)($sr['customer_id'] ?? 0),
                        'branch_id'      => (int)($sr['branch_id'] ?? 1),
                        'revenue_amount' => $revenueAmount,
                        'cogs_amount'    => $cogsAmount,
                    ]);

                    if (($journalResult['status'] ?? '') === 'error') {
                        $result['errors'][] = ($sr['return_code'] ?? $returnId) . ': ' . ($journalResult['message'] ?? 'journal error');
                        continue;
                    }

                    if (!empty($journalResult['journal_entry_id'])) {
                        $this->db->query('UPDATE sales_returns SET journal_entry_id = :jid WHERE id = :id');
                        $this->db->bind(':jid', (int)$journalResult['journal_entry_id']);
                        $this->db->bind(':id', $returnId);
                        $this->db->execute();
                        $result['posted']++;
                    }
                } catch (Exception $e) {
                    $result['errors'][] = ($sr['return_code'] ?? $returnId) . ': ' . $e->getMessage();
                }
            }
        } catch (Exception $e) {
            error_log('SalesAuditModel::repairMissingSalesReturnJournals: ' . $e->getMessage());
            $result['errors'][] = $e->getMessage();
        }

        return $result;
    }

    private function returnHasCustomerLedger(int $returnId): bool
    {
        $this->db->query("
            SELECT 1 FROM customer_ledger
            WHERE reference_type = 'sales_return' AND reference_id = :id
            LIMIT 1
        ");
        $this->db->bind(':id', $returnId);
        return (bool)$this->db->single();
    }

    private function insertCustomerLedgerForReturn(array $sr): void
    {
        $customerId = (int)($sr['customer_id'] ?? 0);
        $returnId   = (int)($sr['id'] ?? 0);
        $amount     = round((float)($sr['total_amount'] ?? 0), 2);
        if ($customerId <= 0 || $returnId <= 0 || $amount <= 0) {
            return;
        }

        $prev = $this->getCustomerRunningBalance($customerId);
        $new  = $prev - $amount;
        $txnDate = $sr['return_date'] ?? date('Y-m-d');

        $branchId = (int)($sr['branch_id'] ?? 0);
        $this->insertCustomerLedgerEntry([
            'customer_id'      => $customerId,
            'reference_type'   => 'sales_return',
            'reference_id'     => $returnId,
            'debit'            => 0,
            'credit'           => $amount,
            'running_balance'  => $new,
            'branch_id'        => $branchId,
            'transaction_date' => $txnDate,
            'remarks'          => 'Sales Return #' . ($sr['return_code'] ?? $returnId),
            'created_by'       => 1,
        ]);
    }

    /**
     * Link orphan payment journals and post missing customer_payment GL from payment header.
     *
     * @return array{linked: int, posted: int, errors: string[]}
     */
    public function repairMissingCustomerPaymentJournals(): array
    {
        $result = ['linked' => 0, 'posted' => 0, 'errors' => []];

        try {
            $branchSql = $this->branchFilter('cp.branch_id');

            $this->db->query("
                UPDATE customer_payments cp
                INNER JOIN journal_entries je ON je.reference_type = 'customer_payment'
                    AND je.reference_id = cp.id
                    AND COALESCE(je.is_reversed, 0) = 0
                SET cp.journal_entry_id = je.id
                WHERE COALESCE(cp.is_reversed, 0) = 0
                  AND COALESCE(cp.journal_entry_id, 0) = 0
                  {$branchSql}
            ");
            $this->db->execute();
            $result['linked'] = $this->db->rowCount();

            $this->db->query("
                SELECT cp.id, cp.payment_code, cp.payment_date, cp.amount, cp.payment_mode,
                       cp.bank_id, cp.customer_id, cp.branch_id, cp.transaction_type
                FROM customer_payments cp
                WHERE COALESCE(cp.is_reversed, 0) = 0
                  AND COALESCE(cp.journal_entry_id, 0) = 0
                  AND COALESCE(cp.transaction_type, 'receive') IN ('receive', 'payment', 'discount', 'write_off')
                  AND cp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
                  AND cp.amount > 0
                  {$branchSql}
                ORDER BY cp.id ASC
                LIMIT 50
            ");
            $payments = $this->db->resultSet() ?: [];

            require_once __DIR__ . '/../services/Accounting/JournalPostingService.php';
            $journalService = new JournalPostingService();

            foreach ($payments as $cp) {
                $paymentId = (int)$cp['id'];
                $amount = round((float)($cp['amount'] ?? 0), 2);
                if ($amount <= 0) {
                    continue;
                }

                try {
                    $txnType = $cp['transaction_type'] ?? 'receive';
                    $journalResult = $journalService->postCustomerTransactionJournal($paymentId, [
                        'payment_code'  => $cp['payment_code'] ?? ('PAY-' . $paymentId),
                        'payment_date'  => $cp['payment_date'] ?? date('Y-m-d'),
                        'customer_id'   => (int)($cp['customer_id'] ?? 0),
                        'amount'        => $amount,
                        'payment_mode'  => $cp['payment_mode'] ?? 'cash',
                        'bank_id'       => !empty($cp['bank_id']) ? (int)$cp['bank_id'] : null,
                        'branch_id'     => (int)($cp['branch_id'] ?? 1),
                    ], $txnType);

                    if (($journalResult['status'] ?? '') === 'error') {
                        $result['errors'][] = ($cp['payment_code'] ?? $paymentId) . ': ' . ($journalResult['message'] ?? 'journal error');
                        continue;
                    }

                    if (!empty($journalResult['journal_entry_id'])) {
                        $this->db->query('UPDATE customer_payments SET journal_entry_id = :jid WHERE id = :id');
                        $this->db->bind(':jid', (int)$journalResult['journal_entry_id']);
                        $this->db->bind(':id', $paymentId);
                        $this->db->execute();
                        $result['posted']++;
                    }
                } catch (Exception $e) {
                    $result['errors'][] = ($cp['payment_code'] ?? $paymentId) . ': ' . $e->getMessage();
                }
            }
        } catch (Exception $e) {
            error_log('SalesAuditModel::repairMissingCustomerPaymentJournals: ' . $e->getMessage());
            $result['errors'][] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Link orphan COGS journals and post missing sales_challan COGS from stock transaction history.
     *
     * @return array{linked: int, posted: int, skipped_no_cogs: int, errors: string[]}
     */
    public function repairMissingChallanCogsJournals(): array
    {
        $result = ['linked' => 0, 'posted' => 0, 'skipped_no_cogs' => 0, 'errors' => []];

        try {
            $branchSql = $this->branchId
                ? ' AND si.branch_id = ' . (int)$this->branchId
                : '';

            $this->db->query("
                UPDATE sales_challans sc
                INNER JOIN sales_invoices si ON si.id = sc.sales_invoice_id
                INNER JOIN journal_entries je ON je.reference_type = 'sales_challan'
                    AND je.reference_id = sc.id
                    AND COALESCE(je.is_reversed, 0) = 0
                SET sc.journal_entry_id = je.id
                WHERE COALESCE(sc.is_reversed, 0) = 0
                  AND COALESCE(sc.journal_entry_id, 0) = 0
                  {$branchSql}
            ");
            $this->db->execute();
            $result['linked'] = $this->db->rowCount();

            $this->db->query("
                SELECT sc.id, sc.challan_code, sc.challan_date, si.branch_id,
                       (
                           SELECT COALESCE(SUM(ABS(st.qty) * st.rate), 0)
                           FROM stock_transactions st
                           WHERE st.reference_type = 'sales_challan'
                             AND st.reference_id = sc.id
                             AND st.qty < -0.0001
                       ) AS cogs_amount
                FROM sales_challans sc
                INNER JOIN sales_invoices si ON si.id = sc.sales_invoice_id
                WHERE COALESCE(sc.is_reversed, 0) = 0
                  AND si.status = 'challan_completed'
                  AND COALESCE(sc.journal_entry_id, 0) = 0
                  AND sc.challan_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
                  {$branchSql}
                ORDER BY sc.id ASC
                LIMIT 50
            ");
            $challans = $this->db->resultSet() ?: [];

            require_once __DIR__ . '/../services/Accounting/JournalPostingService.php';
            $journalService = new JournalPostingService();

            foreach ($challans as $ch) {
                $challanId = (int)$ch['id'];
                $cogsAmount = round((float)($ch['cogs_amount'] ?? 0), 2);

                if ($cogsAmount <= 0.0001) {
                    $result['skipped_no_cogs']++;
                    continue;
                }

                try {
                    $cogsResult = $journalService->postSalesChallanCOGS($challanId, [
                        'challan_code'  => $ch['challan_code'] ?? ('CH-' . $challanId),
                        'challan_date'  => $ch['challan_date'] ?? date('Y-m-d'),
                        'cogs_amount'   => $cogsAmount,
                        'branch_id'     => (int)($ch['branch_id'] ?? 1),
                    ]);

                    if (($cogsResult['status'] ?? '') === 'error') {
                        $result['errors'][] = ($ch['challan_code'] ?? $challanId) . ': ' . ($cogsResult['message'] ?? 'COGS error');
                        continue;
                    }

                    if (!empty($cogsResult['journal_entry_id'])) {
                        $this->db->query('UPDATE sales_challans SET journal_entry_id = :jid WHERE id = :id');
                        $this->db->bind(':jid', (int)$cogsResult['journal_entry_id']);
                        $this->db->bind(':id', $challanId);
                        $this->db->execute();
                        $result['posted']++;
                    }
                } catch (Exception $e) {
                    $result['errors'][] = ($ch['challan_code'] ?? $challanId) . ': ' . $e->getMessage();
                }
            }
        } catch (Exception $e) {
            error_log('SalesAuditModel::repairMissingChallanCogsJournals: ' . $e->getMessage());
            $result['errors'][] = $e->getMessage();
        }

        return $result;
    }

    public function getChallansMissingCogsRows(int $limit = 15): array
    {
        try {
            $this->db->query("
                SELECT sc.id AS challan_id, sc.challan_code, sc.challan_date,
                       si.invoice_code, si.id AS invoice_id,
                       (
                           SELECT COALESCE(SUM(ABS(st.qty) * st.rate), 0)
                           FROM stock_transactions st
                           WHERE st.reference_type = 'sales_challan'
                             AND st.reference_id = sc.id
                             AND st.qty < -0.0001
                       ) AS cogs_amount
                FROM sales_challans sc
                INNER JOIN sales_invoices si ON si.id = sc.sales_invoice_id
                WHERE COALESCE(sc.is_reversed, 0) = 0
                  AND si.status = 'challan_completed'
                  AND COALESCE(sc.journal_entry_id, 0) = 0
                  AND sc.challan_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
                  {$this->branchFilter('si.branch_id')}
                HAVING cogs_amount > 0.0001
                ORDER BY sc.challan_date DESC
                LIMIT " . (int)$limit
            );
            return $this->db->resultSet() ?: [];
        } catch (Exception $e) {
            error_log('SalesAuditModel::getChallansMissingCogsRows: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Link orphan GL entries, post missing sales_invoice journals, backfill customer_ledger when absent.
     *
     * @return array{linked: int, journals_posted: int, ledgers_added: int, errors: string[]}
     */
    public function repairMissingInvoiceAccounting(): array
    {
        $result = ['linked' => 0, 'journals_posted' => 0, 'ledgers_added' => 0, 'errors' => []];

        try {
            $branchSql = $this->branchId
                ? ' AND si.branch_id = ' . (int)$this->branchId
                : '';

            $this->db->query("
                UPDATE sales_invoices si
                INNER JOIN journal_entries je ON je.reference_type = 'sales_invoice'
                    AND je.reference_id = si.id
                    AND COALESCE(je.is_reversed, 0) = 0
                SET si.journal_entry_id = je.id
                WHERE si.is_reversed = 0
                  AND COALESCE(si.journal_entry_id, 0) = 0
                  AND si.total_amount > 0
                  {$branchSql}
            ");
            $this->db->execute();
            $result['linked'] = $this->db->rowCount();

            $this->db->query("
                SELECT si.*
                FROM sales_invoices si
                WHERE si.is_reversed = 0
                  AND si.total_amount > 0
                  AND COALESCE(si.journal_entry_id, 0) = 0
                  AND si.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
                  {$branchSql}
                ORDER BY si.id ASC
                LIMIT 50
            ");
            $invoices = $this->db->resultSet() ?: [];

            require_once __DIR__ . '/../services/Accounting/JournalPostingService.php';
            $journalService = new JournalPostingService();

            foreach ($invoices as $inv) {
                $invoiceId = (int)$inv['id'];
                try {
                    if (!$this->invoiceHasCustomerLedger($invoiceId)) {
                        $this->insertCustomerLedgerForInvoice($inv);
                        $result['ledgers_added']++;
                    }

                    $journalResult = $journalService->postSalesInvoice($invoiceId, [
                        'invoice_code'   => $inv['invoice_code'] ?? ('SI-' . $invoiceId),
                        'invoice_date'   => $inv['invoice_date'] ?? date('Y-m-d'),
                        'customer_id'    => (int)($inv['customer_id'] ?? 0),
                        'branch_id'      => (int)($inv['branch_id'] ?? 1),
                        'subtotal'       => (float)($inv['subtotal'] ?? 0),
                        'discount'       => (float)($inv['discount'] ?? 0),
                        'transport_cost' => (float)($inv['transport_cost'] ?? 0),
                        'total_amount'   => (float)($inv['total_amount'] ?? 0),
                    ]);

                    if (($journalResult['status'] ?? '') === 'error') {
                        $result['errors'][] = ($inv['invoice_code'] ?? $invoiceId) . ': ' . ($journalResult['message'] ?? 'journal error');
                        continue;
                    }

                    if (!empty($journalResult['journal_entry_id'])) {
                        $this->db->query('UPDATE sales_invoices SET journal_entry_id = :jid WHERE id = :id');
                        $this->db->bind(':jid', (int)$journalResult['journal_entry_id']);
                        $this->db->bind(':id', $invoiceId);
                        $this->db->execute();
                        $result['journals_posted']++;
                    }
                } catch (Exception $e) {
                    $result['errors'][] = ($inv['invoice_code'] ?? $invoiceId) . ': ' . $e->getMessage();
                }
            }
        } catch (Exception $e) {
            error_log('SalesAuditModel::repairMissingInvoiceAccounting: ' . $e->getMessage());
            $result['errors'][] = $e->getMessage();
        }

        return $result;
    }

    private function invoiceHasCustomerLedger(int $invoiceId): bool
    {
        $this->db->query("
            SELECT 1 FROM customer_ledger
            WHERE reference_type = 'invoice' AND reference_id = :id
            LIMIT 1
        ");
        $this->db->bind(':id', $invoiceId);
        return (bool)$this->db->single();
    }

    private function getCustomerRunningBalance(int $customerId): float
    {
        $this->db->query("
            SELECT COALESCE(running_balance, 0) AS balance
            FROM customer_ledger
            WHERE customer_id = :cid
            ORDER BY id DESC
            LIMIT 1
        ");
        $this->db->bind(':cid', $customerId);
        $row = $this->db->single();
        return (float)($row['balance'] ?? 0);
    }

    private function insertCustomerLedgerForInvoice(array $inv): void
    {
        $customerId = (int)($inv['customer_id'] ?? 0);
        $invoiceId  = (int)($inv['id'] ?? 0);
        $total      = (float)($inv['total_amount'] ?? 0);
        if ($customerId <= 0 || $invoiceId <= 0 || $total <= 0) {
            return;
        }

        $prev = $this->getCustomerRunningBalance($customerId);
        $new  = $prev + $total;

        $this->insertCustomerLedgerEntry([
            'customer_id'      => $customerId,
            'reference_type'   => 'invoice',
            'reference_id'     => $invoiceId,
            'debit'            => $total,
            'credit'           => 0,
            'running_balance'  => $new,
            'branch_id'        => (int)($inv['branch_id'] ?? 0),
            'transaction_date' => $inv['invoice_date'] ?? date('Y-m-d'),
            'created_by'       => (int)($_SESSION['user_id'] ?? 1),
        ]);
    }

    public function getInvoicesMissingJournalRows(int $limit = 20): array
    {
        try {
            $this->db->query("
                SELECT si.id, si.invoice_code, si.invoice_date, si.status, si.total_amount,
                       (SELECT COUNT(*) FROM customer_ledger cl
                        WHERE cl.reference_type = 'invoice' AND cl.reference_id = si.id) AS has_ledger
                FROM sales_invoices si
                WHERE si.is_reversed = 0
                  AND si.total_amount > 0
                  AND COALESCE(si.journal_entry_id, 0) = 0
                  AND si.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
                  AND (
                      si.status IN ('godown_issued', 'challan_completed')
                      OR EXISTS (
                          SELECT 1 FROM customer_ledger cl
                          WHERE cl.reference_type = 'invoice' AND cl.reference_id = si.id
                      )
                  )
                  {$this->branchFilter('si.branch_id')}
                ORDER BY si.invoice_date DESC, si.id DESC
                LIMIT " . (int)$limit
            );
            return $this->db->resultSet() ?: [];
        } catch (Exception $e) {
            error_log('SalesAuditModel::getInvoicesMissingJournalRows: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Backfill dispatch/item warehouse_id from sales_challan stock OUT when challan posted but row was left NULL.
     */
    public function repairDispatchWarehousesFromChallanStock(): int
    {
        try {
            $branchSql = $this->branchId
                ? ' AND si.branch_id = ' . (int)$this->branchId
                : '';

            $this->db->query("
                UPDATE sales_invoice_dispatches sid
                INNER JOIN sales_invoices si ON si.id = sid.sales_invoice_id
                INNER JOIN sales_challans sc ON sc.sales_invoice_id = si.id AND COALESCE(sc.is_reversed, 0) = 0
                INNER JOIN stock_transactions st ON st.reference_type = 'sales_challan'
                    AND st.reference_id = sc.id
                    AND st.product_id = sid.product_id
                    AND st.qty < -0.0001
                SET sid.warehouse_id = st.warehouse_id,
                    sid.dispatched_at = COALESCE(sid.dispatched_at, st.transaction_date)
                WHERE si.is_reversed = 0
                  AND COALESCE(sid.warehouse_id, 0) = 0
                  AND si.status IN ('godown_issued', 'challan_completed')
                  {$branchSql}
            ");
            $this->db->execute();
            $fixed = $this->db->rowCount();

            $this->db->query("
                UPDATE sales_invoice_items sii
                INNER JOIN sales_invoices si ON si.id = sii.sales_invoice_id
                INNER JOIN sales_invoice_dispatches sid ON sid.sales_invoice_id = si.id
                    AND sid.product_id = sii.product_id
                SET sii.warehouse_id = sid.warehouse_id
                WHERE si.is_reversed = 0
                  AND COALESCE(sii.warehouse_id, 0) = 0
                  AND COALESCE(sid.warehouse_id, 0) > 0
                  {$branchSql}
            ");
            $this->db->execute();

            $this->db->query("
                UPDATE sales_invoices si
                SET godown_issued_at = COALESCE(godown_issued_at, challan_completed_at, NOW())
                WHERE si.is_reversed = 0
                  AND si.status = 'challan_completed'
                  AND si.godown_issued_at IS NULL
                  {$branchSql}
            ");
            $this->db->execute();

            return $fixed;
        } catch (Exception $e) {
            error_log('SalesAuditModel::repairDispatchWarehousesFromChallanStock: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Post-godown dispatch rows still missing warehouse (for checklist detail table).
     */
    public function getInvalidPostGodownDispatchRows(int $limit = 15): array
    {
        try {
            $this->db->query("
                SELECT sid.id AS dispatch_id, sid.sales_invoice_id, sid.product_id, sid.warehouse_id,
                       si.invoice_code, si.status, p.product_name
                FROM sales_invoice_dispatches sid
                INNER JOIN sales_invoices si ON si.id = sid.sales_invoice_id
                LEFT JOIN products p ON p.id = sid.product_id
                WHERE si.is_reversed = 0
                  AND si.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
                  AND (
                      si.godown_issued_at IS NOT NULL
                      OR si.status IN ('godown_issued', 'challan_completed')
                  )
                  AND (
                      COALESCE(sid.warehouse_id, 0) = 0
                      OR NOT EXISTS (SELECT 1 FROM warehouses w WHERE w.id = sid.warehouse_id)
                  )
                  {$this->branchFilter('si.branch_id')}
                ORDER BY si.invoice_date DESC, sid.id DESC
                LIMIT " . (int)$limit
            );
            return $this->db->resultSet() ?: [];
        } catch (Exception $e) {
            error_log('SalesAuditModel::getInvalidPostGodownDispatchRows: ' . $e->getMessage());
            return [];
        }
    }

    private function sectionModuleScope(): array
    {
        return [
            'id'    => 'scope',
            'title' => 'Sales module scope',
            'icon'  => 'fa-sitemap',
            'items' => [
                $this->item('scope_masters', 'reference', 'Master data', 'Products, customers, warehouses, salesmen — required before invoicing.', 'info', null, true),
                $this->item('scope_flow', 'reference', 'Transaction flow', 'Invoice (AR) → godown dispatch (soft hold) → challan (stock OUT + COGS) → optional sales return (stock IN) → customer payment.', 'info', null, true),
                $this->item('scope_stock', 'reference', 'Inventory impact', 'Stock moves on challan OUT and good return IN only — not on draft invoice alone.', 'info', null, true),
                $this->item('scope_gl', 'reference', 'Accounting impact', 'Invoice: Dr AR / Cr revenue. Challan: COGS. Return: reverse revenue + COGS. Payment: Dr bank / Cr AR.', 'info', null, true),
            ],
        ];
    }

    private function sectionProducts(): array
    {
        $inactiveOnInv = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM sales_invoice_items sii
            INNER JOIN sales_invoices si ON si.id = sii.sales_invoice_id
            INNER JOIN products p ON p.id = sii.product_id
            WHERE si.is_reversed = 0
              AND si.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              AND COALESCE(p.is_active, 1) = 0
              {$this->branchFilter('si.branch_id')}
        ");

        $orphan = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM sales_invoice_items sii
            INNER JOIN sales_invoices si ON si.id = sii.sales_invoice_id
            WHERE si.is_reversed = 0
              AND si.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              AND NOT EXISTS (SELECT 1 FROM products p WHERE p.id = sii.product_id)
              {$this->branchFilter('si.branch_id')}
        ");

        $soldSkus = $this->scalarCount("
            SELECT COUNT(DISTINCT sii.product_id) AS c
            FROM sales_invoice_items sii
            INNER JOIN sales_invoices si ON si.id = sii.sales_invoice_id
            WHERE si.is_reversed = 0
              AND si.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              {$this->branchFilter('si.branch_id')}
        ");

        return [
            'id'    => 'products',
            'title' => 'Products (sold SKUs)',
            'icon'  => 'fa-cube',
            'items' => [
                $this->item('prod_shared', 'reference', 'Shared product master', 'Same products for purchase and sales; invoice lines use product_id + rate.', 'info', null, true, 'Product'),
                $this->item('prod_sold', 'auto', 'Distinct products sold (last 12 mo)', 'Unique product_id on non-reversed invoices.', $soldSkus > 0 ? 'pass' : 'warn', $soldSkus > 0 ? "{$soldSkus} SKU(s)" : 'No invoice lines in period'),
                $this->item('prod_inactive', 'auto', 'No inactive products on invoices', 'Invoice lines should reference active SKUs.', $inactiveOnInv === 0 ? 'pass' : 'warn', $inactiveOnInv === 0 ? 'OK' : "{$inactiveOnInv} line(s) with inactive product"),
                $this->item('prod_orphan', 'auto', 'Invoice lines have valid product_id', 'Every sales_invoice_items.product_id must exist.', $orphan === 0 ? 'pass' : 'fail', $orphan === 0 ? 'OK' : "{$orphan} line(s) missing product"),
            ],
        ];
    }

    private function sectionCustomers(): array
    {
        $active = $this->scalarCount("SELECT COUNT(*) AS c FROM customers WHERE is_active = 1");

        $invNoCust = $this->scalarCount("
            SELECT COUNT(*) AS c FROM sales_invoices si
            WHERE si.is_reversed = 0
              AND COALESCE(si.customer_id, 0) = 0
              AND si.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              {$this->branchFilter('si.branch_id')}
        ");

        $invBadCust = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM sales_invoices si
            LEFT JOIN customers c ON c.id = si.customer_id
            WHERE si.is_reversed = 0
              AND si.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              AND (c.id IS NULL OR COALESCE(c.is_active, 0) = 0)
              {$this->branchFilter('si.branch_id')}
        ");

        return [
            'id'    => 'customers',
            'title' => 'Customers',
            'icon'  => 'fa-users',
            'items' => [
                $this->item('cust_master', 'reference', 'Customer master', 'Credit limit, route, shop name; used on every invoice and return.', 'info', null, true, 'customer'),
                $this->item('cust_active', 'auto', 'Active customers available', 'At least one active customer for sales.', $active > 0 ? 'pass' : 'warn', $active > 0 ? "{$active} active" : 'No active customers'),
                $this->item('cust_on_invoice', 'auto', 'Invoices have customer_id', 'Non-reversed invoices must link to a customer.', $invNoCust === 0 ? 'pass' : 'fail', $invNoCust === 0 ? 'OK' : "{$invNoCust} invoice(s) without customer"),
                $this->item('cust_active_use', 'auto', 'Invoices use active customers', 'No missing or deactivated customer on invoices in period.', $invBadCust === 0 ? 'pass' : 'warn', $invBadCust === 0 ? 'OK' : "{$invBadCust} invoice(s) with inactive/missing customer"),
                $this->item('cust_audit', 'reference', 'Customer change audit', 'customer/audit — separate user action log.', 'info', null, true, 'customer/audit'),
            ],
        ];
    }

    private function sectionWarehousesAndDispatch(): array
    {
        // Draft invoices intentionally insert dispatches with warehouse_id NULL (soft hold until godown save).
        $invalidWh = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM sales_invoice_dispatches sid
            INNER JOIN sales_invoices si ON si.id = sid.sales_invoice_id
            WHERE si.is_reversed = 0
              AND si.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              AND (
                  si.godown_issued_at IS NOT NULL
                  OR si.status IN ('godown_issued', 'challan_completed')
              )
              AND (
                  COALESCE(sid.warehouse_id, 0) = 0
                  OR NOT EXISTS (SELECT 1 FROM warehouses w WHERE w.id = sid.warehouse_id)
              )
              {$this->branchFilter('si.branch_id')}
        ");

        $draftNullWh = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM sales_invoice_dispatches sid
            INNER JOIN sales_invoices si ON si.id = sid.sales_invoice_id
            WHERE si.is_reversed = 0
              AND si.status = 'draft'
              AND si.godown_issued_at IS NULL
              AND COALESCE(sid.warehouse_id, 0) = 0
              {$this->branchFilter('si.branch_id')}
        ");

        $branchMismatch = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM sales_invoice_dispatches sid
            INNER JOIN sales_invoices si ON si.id = sid.sales_invoice_id
            INNER JOIN warehouses w ON w.id = sid.warehouse_id
            WHERE si.is_reversed = 0
              AND w.branch_id IS NOT NULL AND si.branch_id IS NOT NULL
              AND w.branch_id != si.branch_id
              {$this->branchFilter('si.branch_id')}
        ");

        return [
            'id'    => 'warehouses',
            'title' => 'Warehouses & dispatch holds',
            'icon'  => 'fa-warehouse',
            'items' => [
                $this->item('wh_soft_hold', 'reference', 'Soft hold: sales_invoice_dispatches', 'On invoice create, warehouse_id is NULL until godown is saved — that reserves qty without picking a warehouse yet.', 'info', null, true),
                $this->item('wh_avail_helper', 'reference', 'Read via Helper', 'Available qty = warehouse_stock − pending (ordered − dispatched) on open invoices.', 'info', null, true),
                $this->item('wh_draft_null', 'auto', 'Draft soft-holds without warehouse (expected)', 'Dispatch rows on draft invoices before godown — not a failure.', 'info', $draftNullWh === 0 ? 'None' : "{$draftNullWh} row(s) awaiting godown"),
                $this->item('wh_valid', 'auto', 'Post-godown dispatches have valid warehouse', 'After godown save, every dispatch row must have warehouse_id pointing to an active warehouse.', $invalidWh === 0 ? 'pass' : 'fail', $invalidWh === 0 ? 'OK' : "{$invalidWh} post-godown row(s) invalid — re-save godown"),
                $this->item('wh_branch', 'auto', 'Dispatch warehouse matches invoice branch', 'Cross-branch warehouse on dispatch should be zero.', $branchMismatch === 0 ? 'pass' : 'warn', $branchMismatch === 0 ? 'OK' : "{$branchMismatch} row(s) branch mismatch"),
            ],
        ];
    }

    private function sectionStockSsot(): array
    {
        $refs = "'" . implode("','", $this->salesStockRefs) . "'";

        $neg = $this->scalarCount("
            SELECT COUNT(*) AS c FROM warehouse_stock ws
            WHERE ws.qty < -0.0001
            {$this->branchWarehouseFilter('ws.warehouse_id')}
        ");

        $orphanMovements = $this->scalarCount("
            SELECT COUNT(*) AS c FROM stock_transactions st
            WHERE st.reference_type IN ({$refs})
              AND st.reference_id > 0
              AND NOT EXISTS (
                  SELECT 1 FROM warehouse_stock ws
                  WHERE ws.warehouse_id = st.warehouse_id AND ws.product_id = st.product_id
              )
            {$this->branchWarehouseFilter('st.warehouse_id')}
        ");

        $recentMoves = $this->scalarCount("
            SELECT COUNT(*) AS c FROM stock_transactions st
            WHERE st.reference_type IN ({$refs})
              AND st.transaction_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
            {$this->branchWarehouseFilter('st.warehouse_id')}
        ");

        return [
            'id'    => 'stock',
            'title' => 'Stock — single source of truth',
            'icon'  => 'fa-boxes',
            'items' => [
                $this->item('stock_read', 'reference', 'Read: warehouse_stock', 'On-hand and avg_cost; sales UI subtracts open dispatch soft-holds.', 'info', null, true),
                $this->item('stock_write', 'reference', 'Write: StockTransactionModel only', 'ChallanModel and SalesReturnModel (confirm) — no direct UPDATE warehouse_stock in sales code.', 'info', null, true),
                $this->item('stock_invoice_no_move', 'reference', 'Invoice alone does not move stock', 'Physical OUT happens on challan; return IN on warehouse confirm (Good).', 'info', null, true),
                $this->item('stock_moves', 'auto', 'Sales stock movements logged (last 12 mo)', 'stock_transactions for challan/return types.', $recentMoves > 0 ? 'pass' : 'warn', $recentMoves > 0 ? "{$recentMoves} movement(s)" : 'No sales stock movements in period'),
                $this->item('stock_negative', 'auto', 'No negative warehouse balances', 'Fix via stock adjustment if legacy rows exist.', $neg === 0 ? 'pass' : 'fail', $neg === 0 ? 'OK' : "{$neg} row(s) below zero — see table"),
                $this->item('stock_orphan', 'auto', 'Movements linked to warehouse_stock', 'Each sales stock_transaction has matching warehouse_stock.', $orphanMovements === 0 ? 'pass' : 'warn', $orphanMovements === 0 ? 'OK' : "{$orphanMovements} orphan movement(s)"),
            ],
        ];
    }

    private function sectionSalesInvoice(): array
    {
        // Draft-only missing journal is informational (pre-GL or not yet finalized).
        $draftNoJournal = $this->scalarCount("
            SELECT COUNT(*) AS c FROM sales_invoices si
            WHERE si.is_reversed = 0
              AND si.status = 'draft'
              AND si.total_amount > 0
              AND COALESCE(si.journal_entry_id, 0) = 0
              AND si.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              {$this->branchFilter('si.branch_id')}
        ");

        $noJournal = $this->scalarCount("
            SELECT COUNT(*) AS c FROM sales_invoices si
            WHERE si.is_reversed = 0
              AND si.total_amount > 0
              AND COALESCE(si.journal_entry_id, 0) = 0
              AND si.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              AND (
                  si.status IN ('godown_issued', 'challan_completed')
                  OR EXISTS (
                      SELECT 1 FROM customer_ledger cl
                      WHERE cl.reference_type = 'invoice' AND cl.reference_id = si.id
                  )
              )
              {$this->branchFilter('si.branch_id')}
        ");

        $noLedger = $this->scalarCount("
            SELECT COUNT(*) AS c FROM sales_invoices si
            WHERE si.is_reversed = 0
              AND si.total_amount > 0
              AND si.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              {$this->branchFilter('si.branch_id')}
              AND NOT EXISTS (
                  SELECT 1 FROM customer_ledger cl
                  WHERE cl.reference_type = 'invoice' AND cl.reference_id = si.id
              )
        ");

        $staleDraftDays = defined('SALES_STALE_DRAFT_DAYS') ? max(1, (int)SALES_STALE_DRAFT_DAYS) : 14;

        $draftOld = $this->scalarCount("
            SELECT COUNT(*) AS c FROM sales_invoices si
            WHERE si.is_reversed = 0
              AND si.status = 'draft'
              AND si.godown_issued_at IS NULL
              AND si.created_at < DATE_SUB(CURDATE(), INTERVAL {$staleDraftDays} DAY)
              {$this->branchFilter('si.branch_id')}
        ");

        $invRevUnreversed = $this->countReversedDocsWithUnreversedJournal('sales_invoices', 'si');

        return [
            'id'    => 'invoice',
            'title' => 'Sales invoice',
            'icon'  => 'fa-file-invoice-dollar',
            'items' => [
                $this->item('inv_create', 'reference', 'Create → AR journal + customer_ledger', 'JournalPostingService::postSalesInvoice; blocks edit after godown or payment.', 'info', null, true, 'sales/create'),
                $this->item('inv_edit_rules', 'reference', 'Edit restrictions', 'Cannot edit after godown_issued_at or if payments allocated; delete only draft without godown/challan.', 'info', null, true),
                $this->item('inv_cart', 'reference', 'Draft carts (session)', 'sales_draft_carts per customer until invoice saved.', 'info', null, true),
                $this->item('inv_journal_draft', 'auto', 'Draft invoices without GL journal', 'Draft may lack journal if created before GL was enabled — informational only.', 'info', $draftNoJournal === 0 ? 'None' : "{$draftNoJournal} draft(s)"),
                $this->item('inv_journal', 'auto', 'Posted invoices have GL journal (last 12 mo)', 'Challan/godown invoices or any with customer_ledger should have journal_entry_id (auto-repair runs on checklist load).', $noJournal === 0 ? 'pass' : 'warn', $noJournal === 0 ? 'OK' : "{$noJournal} invoice(s) still missing journal"),
                $this->item('inv_rev_journal', 'auto', 'Reversed invoices reversed in GL', 'Deleted/reversed invoice should reverse linked sales_invoice journal.', $invRevUnreversed === 0 ? 'pass' : 'warn', $invRevUnreversed === 0 ? 'OK' : "{$invRevUnreversed} reversed invoice(s) with unreversed journal"),
                $this->item('inv_ledger', 'auto', 'Invoices have customer_ledger row', 'Running balance updated on invoice post.', $noLedger === 0 ? 'pass' : 'warn', $noLedger === 0 ? 'OK' : "{$noLedger} invoice(s) without customer_ledger"),
                $this->item('inv_stale_draft', 'auto', "Stale draft invoices (>{$staleDraftDays} days)", 'Drafts left open — auto-cancel on Today\'s Sales or run cancel_stale_drafts.', $draftOld === 0 ? 'pass' : 'warn', $draftOld === 0 ? 'OK' : "{$draftOld} old draft(s)"),
                $this->item('inv_today', 'reference', 'Today invoices workspace', 'sales/today — payments, call-a-day, filters.', 'info', null, true, 'sales/today'),
            ],
        ];
    }

    private function sectionGodown(): array
    {
        $godownNoDispatch = $this->scalarCount("
            SELECT COUNT(*) AS c FROM sales_invoices si
            WHERE si.is_reversed = 0
              AND si.status = 'godown_issued'
              AND si.godown_issued_at >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              {$this->branchFilter('si.branch_id')}
              AND NOT EXISTS (
                  SELECT 1 FROM sales_invoice_dispatches sid
                  WHERE sid.sales_invoice_id = si.id AND COALESCE(sid.warehouse_id, 0) > 0
              )
        ");

        $draftAwaitingGodown = $this->scalarCount("
            SELECT COUNT(*) AS c FROM sales_invoices si
            WHERE si.is_reversed = 0
              AND si.status = 'draft'
              AND si.godown_issued_at IS NULL
              AND EXISTS (SELECT 1 FROM sales_invoice_dispatches sid WHERE sid.sales_invoice_id = si.id)
              {$this->branchFilter('si.branch_id')}
        ");

        $draftPartialGodown = $this->scalarCount("
            SELECT COUNT(*) AS c FROM sales_invoices si
            WHERE si.is_reversed = 0
              AND si.status = 'draft'
              AND si.godown_issued_at IS NULL
              {$this->branchFilter('si.branch_id')}
              AND EXISTS (
                  SELECT 1 FROM sales_invoice_dispatches sid
                  WHERE sid.sales_invoice_id = si.id
                    AND COALESCE(sid.warehouse_id, 0) > 0
              )
        ");

        $staleDraftDays = defined('SALES_STALE_DRAFT_DAYS') ? max(1, (int)SALES_STALE_DRAFT_DAYS) : 14;

        $draftStaleGodown = $this->scalarCount("
            SELECT COUNT(*) AS c FROM sales_invoices si
            WHERE si.is_reversed = 0
              AND si.status = 'draft'
              AND si.godown_issued_at IS NULL
              AND si.created_at < DATE_SUB(CURDATE(), INTERVAL {$staleDraftDays} DAY)
              {$this->branchFilter('si.branch_id')}
        ");

        return [
            'id'    => 'godown',
            'title' => 'Godown (dispatch setup)',
            'icon'  => 'fa-clipboard-list',
            'items' => [
                $this->item('gd_save', 'reference', 'Save godown → godown_issued', 'Writes sales_invoice_dispatches (warehouse, ordered/dispatched qty); status godown_issued; no stock OUT yet.', 'info', null, true, 'challan'),
                $this->item('gd_before_challan', 'reference', 'Challan requires godown first', 'ChallanModel blocks if godown not saved or dispatch rows missing.', 'info', null, true),
                $this->item('gd_has_dispatch', 'auto', 'Godown-issued invoices have dispatches', 'status=godown_issued should have dispatch rows with warehouse.', $godownNoDispatch === 0 ? 'pass' : 'fail', $godownNoDispatch === 0 ? 'OK' : "{$godownNoDispatch} invoice(s) missing dispatch"),
                $this->item(
                    'gd_draft_soft_hold',
                    'auto',
                    'Draft invoices awaiting godown (normal)',
                    'On invoice create the system inserts sales_invoice_dispatches with warehouse_id NULL — soft stock hold until you save godown in Challan.',
                    'info',
                    $draftAwaitingGodown === 0 ? 'None open' : "{$draftAwaitingGodown} draft(s) in pipeline — open Godown & Challan",
                    false,
                    'challan'
                ),
                $this->item(
                    'gd_draft_partial',
                    'auto',
                    'Draft with warehouse on dispatch but godown not saved',
                    'Abnormal: warehouse was set on a dispatch row but godown_issued_at is still empty — re-save godown or delete draft.',
                    $draftPartialGodown === 0 ? 'pass' : 'warn',
                    $draftPartialGodown === 0 ? 'OK' : "{$draftPartialGodown} draft(s) need godown save",
                    false,
                    'challan'
                ),
                $this->item(
                    'gd_draft_stale',
                    'auto',
                    "Stale draft invoices (>{$staleDraftDays} days)",
                    'Drafts holding pipeline — auto-cancelled on Today\'s Sales or use Release pipeline.',
                    $draftStaleGodown === 0 ? 'pass' : 'warn',
                    $draftStaleGodown === 0 ? 'OK' : "{$draftStaleGodown} old draft(s)",
                    false,
                    'sales/today'
                ),
            ],
        ];
    }

    private function sectionChallan(): array
    {
        $noStock = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM sales_challans sc
            INNER JOIN sales_invoices si ON si.id = sc.sales_invoice_id
            WHERE COALESCE(sc.is_reversed, 0) = 0
              AND si.status = 'challan_completed'
              AND sc.challan_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              {$this->branchFilter('si.branch_id')}
              AND NOT EXISTS (
                  SELECT 1 FROM stock_transactions st
                  WHERE st.reference_type = 'sales_challan'
                    AND st.reference_id = sc.id
                    AND st.qty < -0.0001
              )
        ");

        $noJournal = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM sales_challans sc
            INNER JOIN sales_invoices si ON si.id = sc.sales_invoice_id
            WHERE COALESCE(sc.is_reversed, 0) = 0
              AND si.status = 'challan_completed'
              AND sc.challan_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              AND COALESCE(sc.journal_entry_id, 0) = 0
              {$this->branchFilter('si.branch_id')}
              AND EXISTS (
                  SELECT 1 FROM stock_transactions st
                  WHERE st.reference_type = 'sales_challan'
                    AND st.reference_id = sc.id
                    AND st.qty < -0.0001
              )
              AND (
                  SELECT COALESCE(SUM(ABS(st.qty) * st.rate), 0)
                  FROM stock_transactions st
                  WHERE st.reference_type = 'sales_challan'
                    AND st.reference_id = sc.id
                    AND st.qty < -0.0001
              ) > 0.0001
        ");

        $cancelNoRev = $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM sales_challans sc
            INNER JOIN journal_entries je ON je.id = sc.journal_entry_id
            WHERE COALESCE(sc.is_reversed, 0) = 1
              AND COALESCE(je.is_reversed, 0) = 0
              {$this->branchFilterOnChallan()}
        ");

        $missingAdjJournal = $this->countChallansMissingAdjustmentJournal();

        return [
            'id'    => 'challan',
            'title' => 'Delivery challan',
            'icon'  => 'fa-truck-loading',
            'items' => [
                $this->item('ch_stock_out', 'reference', 'Complete challan → stock OUT', 'Negative qty in stock_transactions (sales_challan); updates warehouse_stock.', 'info', null, true, 'challan'),
                $this->item('ch_cogs', 'reference', 'COGS journal on challan', 'Dr COGS / Cr inventory via postSalesChallanCOGS.', 'info', null, true),
                $this->item('ch_reverse', 'reference', 'Reverse challan', 'Restores stock at issue rate, reverses COGS + transport GL + customer_ledger adjustment, restores invoice transport/total (Phase 3).', 'info', null, true),
                $this->item('ch_missing_stock', 'auto', 'Completed challans have stock OUT', 'Active challans on challan_completed invoices need sales_challan movements.', $noStock === 0 ? 'pass' : 'fail', $noStock === 0 ? 'OK' : "{$noStock} challan(s) without stock OUT"),
                $this->item('ch_missing_cogs', 'auto', 'Completed challans have COGS journal', 'When stock was issued (sales_challan OUT), Dr COGS / Cr inventory should be posted — auto-repair runs on checklist load.', $noJournal === 0 ? 'pass' : 'warn', $noJournal === 0 ? 'OK' : "{$noJournal} challan(s) still missing COGS journal"),
                $this->item('ch_adj_journal', 'auto', 'Transport adjustments have GL journal', 'sales_challans.adjustment_journal_entry_id when transport_adjustment ≠ 0.', $missingAdjJournal === 0 ? 'pass' : 'warn', $missingAdjJournal === 0 ? 'OK' : "{$missingAdjJournal} challan(s) missing adjustment journal"),
                $this->item('ch_rev_journal', 'auto', 'Reversed challans reversed in GL', 'Reversed challan should reverse linked COGS and adjustment journals.', $cancelNoRev === 0 ? 'pass' : 'warn', $cancelNoRev === 0 ? 'OK' : "{$cancelNoRev} reversed challan(s) with unreversed journal"),
            ],
        ];
    }

    private function sectionSalesReturn(): array
    {
        $pendingOld = $this->scalarCount("
            SELECT COUNT(*) AS c FROM sales_returns sr
            WHERE sr.status = 'pending'
              AND COALESCE(sr.is_reversed, 0) = 0
              AND sr.return_date < DATE_SUB(CURDATE(), INTERVAL 14 DAY)
              {$this->branchFilterViaSalesReturn()}
        ");

        $noStockIn = $this->scalarCount("
            SELECT COUNT(DISTINCT sr.id) AS c
            FROM sales_returns sr
            INNER JOIN sales_return_items sri ON sri.sales_return_id = sr.id
            WHERE sr.status = 'completed'
              AND COALESCE(sr.is_reversed, 0) = 0
              AND sri.`condition` = 'Good'
              AND sri.return_qty > 0
              {$this->branchFilterViaSalesReturn()}
              AND NOT EXISTS (
                  SELECT 1 FROM stock_transactions st
                  WHERE st.reference_type = 'sales_return'
                    AND st.reference_id = sr.id
                    AND st.qty > 0.0001
              )
        ");

        $noJournal = $this->scalarCount("
            SELECT COUNT(*) AS c FROM sales_returns sr
            WHERE sr.status = 'completed'
              AND COALESCE(sr.is_reversed, 0) = 0
              AND sr.return_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              AND COALESCE(sr.journal_entry_id, 0) = 0
              {$this->branchFilterViaSalesReturn()}
        ");

        $reversedNoFlag = $this->scalarCount("
            SELECT COUNT(*) AS c FROM sales_returns sr
            WHERE EXISTS (
                SELECT 1 FROM stock_transactions st
                WHERE st.reference_type = 'sales_return_reversal' AND st.reference_id = sr.id
            )
              AND COALESCE(sr.is_reversed, 0) = 0
              {$this->branchFilterViaSalesReturn()}
        ");

        $srRevUnreversed = $this->countReversedDocsWithUnreversedJournal('sales_returns', 'sr');

        return [
            'id'    => 'return',
            'title' => 'Sales return',
            'icon'  => 'fa-undo-alt',
            'items' => [
                $this->item('sr_flow', 'reference', 'Two-step return', 'Sales creates pending return → warehouse confirms (stock IN for Good, journal, customer_ledger credit).', 'info', null, true, 'SalesReturn'),
                $this->item('sr_returnable', 'reference', 'returnable_qty ≠ on-hand', 'Caps return per invoice line; warehouse qty validated separately on confirm.', 'info', null, true),
                $this->item('sr_damage', 'reference', 'Damage → no stock IN', 'Damage lines log zero-qty movement; no warehouse_stock increase.', 'info', null, true),
                $this->item('sr_pending', 'auto', 'Pending returns >14 days', 'Awaiting warehouse confirm — follow up.', $pendingOld === 0 ? 'pass' : 'warn', $pendingOld === 0 ? 'OK' : "{$pendingOld} pending return(s)"),
                $this->item('sr_stock_in', 'auto', 'Completed Good returns have stock IN', 'Positive sales_return stock_transactions.', $noStockIn === 0 ? 'pass' : 'fail', $noStockIn === 0 ? 'OK' : "{$noStockIn} return(s) missing stock IN"),
                $this->item('sr_journal', 'auto', 'Completed returns have journal', 'postSalesReturn on warehouse confirm — auto-repair runs on checklist load.', $noJournal === 0 ? 'pass' : 'warn', $noJournal === 0 ? 'OK' : "{$noJournal} return(s) still missing journal"),
                $this->item('sr_rev_journal', 'auto', 'Reversed returns reversed in GL', 'Reversed return should reverse linked sales_return journal.', $srRevUnreversed === 0 ? 'pass' : 'warn', $srRevUnreversed === 0 ? 'OK' : "{$srRevUnreversed} reversed return(s) with unreversed journal"),
                $this->item('sr_rev_flag', 'auto', 'Reversal stock matches is_reversed', 'sales_return_reversal requires is_reversed=1.', $reversedNoFlag === 0 ? 'pass' : 'warn', $reversedNoFlag === 0 ? 'OK' : "{$reversedNoFlag} return(s) mismatch"),
            ],
        ];
    }

    private function sectionCustomerPayments(): array
    {
        $noLedger = $this->scalarCount("
            SELECT COUNT(*) AS c FROM customer_payments cp
            WHERE COALESCE(cp.is_reversed, 0) = 0
              AND cp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              {$this->branchFilter('cp.branch_id')}
              AND NOT EXISTS (
                  SELECT 1 FROM customer_ledger cl
                  WHERE cl.reference_id = cp.id
                    AND cl.reference_type IN ('payment', 'receive', 'advance')
              )
        ");

        $noJournal = $this->scalarCount("
            SELECT COUNT(*) AS c FROM customer_payments cp
            WHERE COALESCE(cp.is_reversed, 0) = 0
              AND cp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              AND COALESCE(cp.journal_entry_id, 0) = 0
              {$this->branchFilter('cp.branch_id')}
        ");

        $recent = $this->scalarCount("
            SELECT COUNT(*) AS c FROM customer_payments cp
            WHERE cp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              {$this->branchFilter('cp.branch_id')}
        ");

        $payRevUnreversed = $this->countReversedPaymentsWithUnreversedJournal();

        return [
            'id'    => 'payments',
            'title' => 'Customer payments & receivable',
            'icon'  => 'fa-hand-holding-usd',
            'items' => [
                $this->item('pay_dual', 'reference', 'AR: GL + customer_ledger', 'Invoice/payment post to customer_ledger; GL uses customer_receivable ledger. Reconcile for month-end.', 'info', null, true),
                $this->item('pay_sales', 'reference', 'Receive payment from sales/today', 'SalesModel::recordCustomerPayment — allocates to invoices, posts journal.', 'info', null, true, 'sales/today'),
                $this->item('pay_acct', 'reference', 'CustomerTransaction module', 'Additional customer receipts/adjustments in accounting.', 'info', null, true, 'CustomerTransaction'),
                $this->item('pay_ledger', 'auto', 'Payments have customer_ledger row', 'Each active payment should update running balance.', $noLedger === 0 ? 'pass' : 'warn', $noLedger === 0 ? 'OK' : "{$noLedger} payment(s) without ledger"),
                $this->item('pay_journal', 'auto', 'Payments have GL journal (last 12 mo)', 'postCustomerTransactionJournal by type (receive/payment/discount/write_off) — auto-repair on checklist load.', $noJournal === 0 ? 'pass' : 'warn', $noJournal === 0 ? 'OK' : "{$noJournal} payment(s) still missing journal"),
                $this->item('pay_rev_journal', 'auto', 'Reversed payments reversed in GL', 'Reversed customer_payment should reverse linked journal.', $payRevUnreversed === 0 ? 'pass' : 'warn', $payRevUnreversed === 0 ? 'OK' : "{$payRevUnreversed} reversed payment(s) with unreversed journal"),
                $this->item('pay_activity', 'auto', 'Customer payments in period', 'Informational count.', $recent > 0 ? 'pass' : 'info', $recent > 0 ? "{$recent} payment(s)" : 'No payments in period'),
            ],
        ];
    }

    private function sectionGlJournalLinks(): array
    {
        return [
            'id'    => 'gl_links',
            'title' => 'GL journal link columns',
            'icon'  => 'fa-link',
            'items' => [
                $this->item('gl_col_invoice', 'reference', 'sales_invoices.journal_entry_id', 'Invoice AR + revenue journal from postSalesInvoice. View on sales/show/{id}.', 'info', null, true, 'sales/today'),
                $this->item('gl_col_challan_cogs', 'reference', 'sales_challans.journal_entry_id', 'Challan COGS journal (Dr COGS / Cr inventory). View on challan/details/{id}.', 'info', null, true, 'challan'),
                $this->item('gl_col_challan_adj', 'reference', 'sales_challans.adjustment_journal_entry_id', 'Transport/total adjustment at challan finalize (sales_invoice_adjustment).', 'info', null, true, 'challan'),
                $this->item('gl_col_return', 'reference', 'sales_returns.journal_entry_id', 'Return revenue + inventory restore on warehouse confirm. View on SalesReturn/details/{id}.', 'info', null, true, 'SalesReturn'),
                $this->item('gl_col_payment', 'reference', 'customer_payments.journal_entry_id', 'Receipt/refund/discount/write-off from sales/today or CustomerTransaction.', 'info', null, true, 'CustomerTransaction'),
            ],
        ];
    }

    private function countReversedDocsWithUnreversedJournal(string $table, string $alias): int
    {
        if (!in_array($table, ['sales_invoices', 'sales_returns'], true)) {
            return 0;
        }

        $branchSql = $table === 'sales_returns'
            ? $this->branchFilterViaSalesReturn()
            : $this->branchFilter("{$alias}.branch_id");

        return $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM {$table} {$alias}
            INNER JOIN journal_entries je ON je.id = {$alias}.journal_entry_id
            WHERE {$alias}.is_reversed = 1
              AND COALESCE({$alias}.journal_entry_id, 0) > 0
              AND COALESCE(je.is_reversed, 0) = 0
              {$branchSql}
        ");
    }

    private function countReversedPaymentsWithUnreversedJournal(): int
    {
        return $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM customer_payments cp
            INNER JOIN journal_entries je ON je.id = cp.journal_entry_id
            WHERE cp.is_reversed = 1
              AND COALESCE(cp.journal_entry_id, 0) > 0
              AND COALESCE(je.is_reversed, 0) = 0
              {$this->branchFilter('cp.branch_id')}
        ");
    }

    private function countChallansMissingAdjustmentJournal(): int
    {
        try {
            $this->db->query("SHOW COLUMNS FROM sales_challans LIKE 'transport_adjustment'");
            if (!$this->db->single()) {
                return 0;
            }
        } catch (Exception $e) {
            return 0;
        }

        return $this->scalarCount("
            SELECT COUNT(*) AS c
            FROM sales_challans sc
            INNER JOIN sales_invoices si ON si.id = sc.sales_invoice_id
            WHERE COALESCE(sc.is_reversed, 0) = 0
              AND ABS(COALESCE(sc.transport_adjustment, 0)) > 0.01
              AND COALESCE(sc.adjustment_journal_entry_id, 0) = 0
              AND sc.challan_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
              {$this->branchFilter('si.branch_id')}
        ");
    }

    private function sectionLedger(array $reconFull = []): array
    {
        $ar = $this->scalarCount("SELECT COUNT(*) AS c FROM ledgers WHERE ledger_nature = 'customer_receivable' AND is_active = 1");
        $rev = $this->scalarCount("SELECT COUNT(*) AS c FROM ledgers WHERE ledger_nature = 'sales_revenue' AND is_active = 1");
        $cogs = $this->scalarCount("SELECT COUNT(*) AS c FROM ledgers WHERE ledger_nature = 'cogs' AND is_active = 1");
        $inv = $this->scalarCount("SELECT COUNT(*) AS c FROM ledgers WHERE ledger_nature = 'inventory' AND is_active = 1");
        $mismatches = $this->getCustomerLedgerBalanceMismatches(0.02, $this->branchId);
        $mismatchCount = count($mismatches);
        $nullBranchLedger = $this->scalarCount("
            SELECT COUNT(*) AS c FROM customer_ledger cl
            WHERE cl.branch_id IS NULL
            {$this->branchFilter('cl.branch_id')}
        ");
        $arReport = (new ReconciliationService())->getSalesArReport($this->branchId ?: null);
        $arDiff = abs((float)($arReport['ar_difference'] ?? 0));
        $arTol = (float)($arReport['tolerance'] ?? 0.02);

        return [
            'id'    => 'ledger',
            'title' => 'Ledger & accounts (GL)',
            'icon'  => 'fa-book',
            'items' => [
                $this->item('gl_ar', 'reference', 'Accounts receivable', 'Debited on invoice; credited on payment/return.', 'info', null, true),
                $this->item('gl_rev', 'reference', 'Sales revenue', 'Credited on invoice; debited on return.', 'info', null, true),
                $this->item('gl_cogs', 'reference', 'COGS + inventory', 'Challan: Dr COGS / Cr inventory. Return restores inventory.', 'info', null, true),
                $this->item('gl_ar_ok', 'auto', 'AR ledger configured', 'Requires active ledger with ledger_nature customer_receivable.', $ar > 0 ? 'pass' : 'fail', $ar > 0 ? 'OK' : 'Missing — invoice posting fails'),
                $this->item('gl_rev_ok', 'auto', 'Sales revenue ledger configured', 'Requires active ledger with ledger_nature sales_revenue.', $rev > 0 ? 'pass' : 'fail', $rev > 0 ? 'OK' : 'Missing'),
                $this->item('gl_cogs_ok', 'auto', 'COGS ledger configured', 'Requires active ledger with ledger_nature cogs.', $cogs > 0 ? 'pass' : 'fail', $cogs > 0 ? 'OK' : 'Missing — challan COGS fails'),
                $this->item('gl_inv_ok', 'auto', 'Inventory ledger configured', 'Requires active ledger with ledger_nature inventory.', $inv > 0 ? 'pass' : 'fail', $inv > 0 ? 'OK' : 'Missing'),
                $this->item('cl_balance', 'auto', 'Customer ledger running_balance integrity', 'Last row balance vs SUM(debit−credit) per customer (tolerance 0.02).', $mismatchCount === 0 ? 'pass' : 'fail', $mismatchCount === 0 ? 'OK' : "{$mismatchCount} customer(s) mismatch"),
                $this->item('cl_ar_gl', 'auto', 'AR sub-ledger vs GL control', 'Sum of customer latest balances vs net customer_receivable journal lines.', $arDiff <= $arTol ? 'pass' : 'warn', $arDiff <= $arTol ? 'OK' : 'Diff ' . number_format($arDiff, 2), false, 'Reconciliation/index'),
                $this->item('cl_branch', 'auto', 'customer_ledger.branch_id populated', 'New rows set branch_id; run migration 013 to backfill history.', $nullBranchLedger === 0 ? 'pass' : 'warn', $nullBranchLedger === 0 ? 'OK' : "{$nullBranchLedger} row(s) without branch_id"),
                $this->item('pay_seq', 'reference', 'Payment codes', 'PAY-YYYYMMDD-#### via document_sequences (customer_payment).', 'info', null, true),
                $this->item('gl_discount', 'auto', 'Sales discount ledger (optional)', 'When configured (sales_discount), invoice discounts post to contra-revenue instead of net revenue only.', $this->scalarCount("SELECT COUNT(*) AS c FROM ledgers WHERE ledger_nature = 'sales_discount' AND is_active = 1") > 0 ? 'pass' : 'info', $this->scalarCount("SELECT COUNT(*) AS c FROM ledgers WHERE ledger_nature = 'sales_discount' AND is_active = 1") > 0 ? 'Configured' : 'Optional — migration 019 seeds L-1010'),
            ],
        ];
    }

    private function sectionGlReconciliation(array $reconFull): array
    {
        $tol = (float)($reconFull['tolerance'] ?? 0.02);
        $ar = $reconFull['ar'] ?? [];
        $inv = $reconFull['inventory'] ?? [];
        $cogs = $reconFull['cogs'] ?? [];
        $arDiff = abs((float)($ar['difference'] ?? 0));
        $invDiff = abs((float)($inv['difference'] ?? 0));
        $cogsDiff = abs((float)($cogs['difference'] ?? 0));

        return [
            'id'    => 'gl_recon',
            'title' => 'GL reconciliation (Phase 5)',
            'icon'  => 'fa-scale-balanced',
            'items' => [
                $this->item('recon_ar', 'auto', 'AR sub-ledger vs GL', 'Latest customer balances vs customer_receivable journal net.', !empty($ar['within_tolerance']) ? 'pass' : 'warn', !empty($ar['within_tolerance']) ? 'OK' : 'Diff ' . number_format($arDiff, 2), false, 'Reconciliation/index'),
                $this->item('recon_inv', 'auto', 'Inventory GL vs warehouse valuation', 'sum(qty×avg_cost) vs inventory ledger net (cumulative GL).', !empty($inv['within_tolerance']) ? 'pass' : 'warn', !empty($inv['within_tolerance']) ? 'OK' : 'Diff ' . number_format($invDiff, 2), false, 'Reconciliation/index'),
                $this->item('recon_cogs', 'auto', 'COGS tie-out (period)', 'Challan stock OUT vs COGS debits on sales_challan journals.', !empty($cogs['within_tolerance']) ? 'pass' : 'warn', !empty($cogs['within_tolerance']) ? 'OK' : 'Diff ' . number_format($cogsDiff, 2) . ' (' . ($cogs['from_date'] ?? '') . '–' . ($cogs['to_date'] ?? '') . ')', false, 'Reconciliation/index'),
                $this->item('recon_job', 'reference', 'Scheduled reconciliation', 'Cron: php database/scripts/run_gl_reconciliation.php — alerts in logs/reconciliation_alerts.log', 'info', null, true),
            ],
        ];
    }

    private function sectionReports(): array
    {
        $items = [];
        $implemented = [
            ['id' => 'rpt_sales_history', 'title' => 'Sales history', 'route' => 'Report/SalesHistory', 'view' => 'SalesHistory', 'desc' => 'Invoice-level sales by period.'],
            ['id' => 'rpt_return_hist', 'title' => 'Sales return history', 'route' => 'Report/SalesReturnHistory', 'view' => 'SalesReturnHistory', 'desc' => 'Customer returns by period.'],
            ['id' => 'rpt_movement', 'title' => 'Product movement', 'route' => 'Report/ProductMovement', 'view' => 'ProductMovement', 'desc' => 'Includes sales_challan / return types.'],
            ['id' => 'rpt_margin', 'title' => 'Gross margin by invoice/product', 'route' => 'Report/grossMargin', 'view' => 'GrossMargin', 'desc' => 'Revenue vs COGS from challan; delivery or invoice date basis.'],
        ];

        foreach ($implemented as $r) {
            $exists = $this->reportViewExists($r['view']);
            $items[] = $this->item(
                $r['id'],
                'auto',
                $r['title'] . ' — available',
                $r['desc'],
                $exists ? 'pass' : 'warn',
                $exists ? 'Screen exists' : 'View missing',
                false,
                $r['route']
            );
        }

        $planned = [
            ['id' => 'rpt_pipeline', 'title' => 'Sales pipeline (draft → godown → challan)', 'desc' => 'Open invoices by workflow stage. (Planned)'],
            ['id' => 'rpt_godown_pending', 'title' => 'Godown pending register', 'desc' => 'Invoices awaiting dispatch/challan. (Planned)'],
            ['id' => 'rpt_challan_reg', 'title' => 'Challan register (line detail)', 'desc' => 'Delivered qty, warehouse, COGS per challan. (Planned)'],
            ['id' => 'rpt_cust_stmt', 'title' => 'Customer statement (GL + ledger)', 'desc' => 'Opening, invoices, returns, payments, closing. (Planned)'],
            ['id' => 'rpt_credit', 'title' => 'Credit limit exceptions', 'desc' => 'Overrides logged in sales audit. (Planned)'],
        ];

        foreach ($planned as $p) {
            $items[] = $this->item($p['id'], 'reference', $p['title'], $p['desc'], 'info', 'Implement in Reports module later', true);
        }

        return [
            'id'    => 'reports',
            'title' => 'Sales-related reports',
            'icon'  => 'fa-chart-line',
            'items' => $items,
        ];
    }

    private function item(
        string $id,
        string $type,
        string $title,
        string $expected,
        string $status,
        ?string $detail,
        bool $reference = false,
        ?string $route = null
    ): array {
        return [
            'id'        => $id,
            'type'      => $type,
            'title'     => $title,
            'expected'  => $expected,
            'status'    => $status,
            'detail'    => $detail ?? '',
            'reference' => $reference,
            'url'       => $route ? (defined('BASE_URL') ? BASE_URL : '') . $route : null,
        ];
    }

    private function reportViewExists(string $viewName): bool
    {
        return is_file(dirname(__DIR__) . '/views/report/' . $viewName . '.php');
    }

    public function getNegativeStockRows(int $limit = 15): array
    {
        try {
            $this->db->query("
                SELECT ws.warehouse_id, ws.product_id, w.warehouse_name, p.product_name,
                       ws.qty AS ws_qty, ws.avg_cost,
                       (
                           SELECT COALESCE(SUM(st.qty), 0)
                           FROM stock_transactions st
                           WHERE st.warehouse_id = ws.warehouse_id AND st.product_id = ws.product_id
                       ) AS txn_qty_sum
                FROM warehouse_stock ws
                LEFT JOIN warehouses w ON w.id = ws.warehouse_id
                LEFT JOIN products p ON p.id = ws.product_id
                WHERE ws.qty < -0.0001
                {$this->branchWarehouseFilter('ws.warehouse_id')}
                ORDER BY ws.qty ASC
                LIMIT " . (int)$limit
            );
            return $this->db->resultSet() ?: [];
        } catch (Exception $e) {
            error_log('SalesAuditModel::getNegativeStockRows: ' . $e->getMessage());
            return [];
        }
    }

    private function branchFilterOnChallan(): string
    {
        if (!$this->branchId) {
            return '';
        }
        return ' AND EXISTS (
            SELECT 1 FROM sales_invoices si
            WHERE si.id = sc.sales_invoice_id AND si.branch_id = ' . (int)$this->branchId . '
        )';
    }

    private function scalarCount(string $sql): int
    {
        try {
            $this->db->query($sql);
            $row = $this->db->single();
            return (int)($row['c'] ?? 0);
        } catch (Exception $e) {
            error_log('SalesAuditModel: ' . $e->getMessage());
            return -1;
        }
    }

    private function branchFilter(string $column): string
    {
        if (!$this->branchId) {
            return '';
        }
        return " AND {$column} = " . (int)$this->branchId;
    }

    private function branchFilterViaSalesReturn(): string
    {
        if (!$this->branchId) {
            return '';
        }
        return ' AND EXISTS (
            SELECT 1 FROM sales_invoices si
            WHERE si.id = sr.sales_invoice_id AND si.branch_id = ' . (int)$this->branchId . '
        )';
    }

    private function branchWarehouseFilter(string $warehouseColumn): string
    {
        if (!$this->branchId) {
            return '';
        }
        return " AND EXISTS (
            SELECT 1 FROM warehouses w
            WHERE w.id = {$warehouseColumn} AND w.branch_id = " . (int)$this->branchId . '
        )';
    }
}