<?php
// app/models/CustomerTransactionAuditModel.php

require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../helpers/Helper.php';
require_once __DIR__ . '/CustomerTransactionModel.php';
require_once __DIR__ . '/JournalEntryModel.php';

class CustomerTransactionAuditModel
{
    protected Database $db;

    public function __construct()
    {
        $this->db = new Database();
    }

    public function runPaymentChecks(int $paymentId): array
    {
        $model = new CustomerTransactionModel();
        $p = $model->getTransactionById($paymentId);
        if (!$p) {
            return ['items' => [], 'summary' => ['pass' => 0, 'warn' => 0, 'fail' => 0]];
        }

        $items = [];
        $isReversed = !empty($p['is_reversed']);
        $type = (string)($p['transaction_type'] ?? 'receive');
        $amount = round((float)($p['amount'] ?? 0), 2);
        $customerId = (int)($p['customer_id'] ?? 0);

        $items[] = $this->item(
            'branch',
            'auto',
            'Branch voucher',
            'Payment recorded under branch scope.',
            'pass',
            $p['branch_name'] ?? ('Branch #' . ($p['branch_id'] ?? ''))
        );

        $ledgerRows = $this->fetchLedgerRows($paymentId);
        $items[] = $this->item(
            'ledger',
            'auto',
            'Customer ledger',
            'Debit/credit entry with running balance.',
            count($ledgerRows) > 0 ? 'pass' : ($isReversed ? 'info' : 'fail'),
            count($ledgerRows) > 0 ? count($ledgerRows) . ' row(s)' : 'Missing'
        );

        $origLedger = $this->findOriginalLedgerRow($ledgerRows, $type, $amount);
        if ($origLedger && !$isReversed) {
            $ledgerOk = $this->ledgerAmountMatchesType($origLedger, $type, $amount);
            $items[] = $this->item(
                'ledger_amount',
                'auto',
                'Ledger amount',
                'Original row debit/credit must match payment type and amount.',
                $ledgerOk ? 'pass' : 'fail',
                $ledgerOk
                    ? $this->money($amount)
                    : 'Expected ' . $this->expectedLedgerSideLabel($type) . ' ' . $this->money($amount)
            );
        }

        $dueNow = $model->getCustomerDue($customerId);
        $lastBal = $this->getLastRunningBalance($customerId);
        $dueMatch = abs($dueNow - $lastBal) < 0.02;
        $items[] = $this->item(
            'due_balance',
            'auto',
            'Due vs ledger tail',
            'Get_Customer_Now_Due must match last running_balance.',
            $dueMatch ? 'pass' : 'warn',
            'Due ' . $this->money($dueNow) . ' · Ledger ' . $this->money($lastBal)
        );

        $hasJe = !empty($p['journal_entry_id']);
        if ($amount >= 0.01) {
            $items[] = $this->item(
                'gl',
                'auto',
                'General ledger',
                'Double-entry journal for this voucher type.',
                $hasJe ? 'pass' : ($isReversed ? 'info' : 'fail'),
                $hasJe ? 'JE #' . (int)$p['journal_entry_id'] : 'Not posted'
            );
        }

        if ($hasJe) {
            $je = (new JournalEntryModel())->getEntryWithLines((int)$p['journal_entry_id']);
            $glBalanced = $this->journalIsBalanced($je);
            $items[] = $this->item(
                'gl_balanced',
                'auto',
                'GL debits = credits',
                'Journal lines must balance.',
                $glBalanced ? 'pass' : 'fail',
                $glBalanced ? 'Balanced' : 'Out of balance'
            );

            $jeReversed = !empty($je['is_reversed']);
            if ($isReversed) {
                $items[] = $this->item(
                    'gl_reversed',
                    'auto',
                    'Journal reversed',
                    'Reversed payment must have reversed journal.',
                    $jeReversed ? 'pass' : 'fail',
                    $jeReversed ? 'Journal reversed' : 'Journal still active'
                );
            }
        }

        if ($type === 'receive' && strtolower((string)($p['payment_mode'] ?? '')) === 'bank' && !empty($p['bank_id'])) {
            $items[] = $this->item(
                'bank',
                'auto',
                'Bank book',
                'Bank-mode receive updates banks.balance on post; reversal undoes it.',
                'pass',
                $p['bank_name'] ?? 'Bank #' . (int)$p['bank_id']
            );
        }

        if ($type === 'receive' && !$isReversed) {
            $settleCount = $this->scalarCount(
                'SELECT COUNT(*) AS c FROM customer_payment_settlements WHERE payment_id = :pid',
                [':pid' => $paymentId]
            );
            if ($settleCount > 0) {
                $items[] = $this->item(
                    'demand_settle',
                    'auto',
                    'Branch demand settlement',
                    'Receive may allocate to open branch demands.',
                    'pass',
                    $settleCount . ' allocation(s)'
                );
            }
        }

        if ($isReversed) {
            $hasReversalRow = $this->hasReversalLedgerRow($ledgerRows);
            $items[] = $this->item(
                'reversal_ledger',
                'auto',
                'Reversal ledger entry',
                'Compensating reversal row restores customer balance.',
                $hasReversalRow ? 'pass' : 'fail',
                $hasReversalRow ? 'Present' : 'Missing reversal row'
            );

            $items[] = $this->item(
                'reversed',
                'auto',
                'Reversed',
                'GL reversed, ledger compensating entry, payment flagged.',
                !empty($p['reverse_reason']) ? 'pass' : 'warn',
                trim(($p['reverse_reason'] ?? '') . ' · ' . ($p['reversed_at'] ?? ''))
            );
        } elseif (!$isReversed) {
            $items[] = $this->item(
                'can_reverse',
                'reference',
                'Reversal available',
                'Use Reverse — restores ledger, GL, bank (if applicable), and demand settlements for receives.',
                'info',
                'Reason required (min 3 characters)'
            );
        }

        $pass = $warn = $fail = 0;
        foreach ($items as $it) {
            match ($it['status']) {
                'pass' => $pass++,
                'warn' => $warn++,
                'fail' => $fail++,
                default => null,
            };
        }

        return ['items' => $items, 'summary' => ['pass' => $pass, 'warn' => $warn, 'fail' => $fail]];
    }

    private function fetchLedgerRows(int $paymentId): array
    {
        try {
            $this->db->query("
                SELECT * FROM customer_ledger
                WHERE reference_id = :pid
                  AND reference_type IN ('payment', 'reversal')
                ORDER BY id ASC
            ");
            $this->db->bind(':pid', $paymentId);

            return $this->db->resultSet() ?: [];
        } catch (Exception $e) {
            return [];
        }
    }

    private function findOriginalLedgerRow(array $rows, string $type, float $amount): ?array
    {
        foreach ($rows as $row) {
            if (($row['reference_type'] ?? '') !== 'payment') {
                continue;
            }
            if (!empty($row['is_reversed'])) {
                continue;
            }
            $d = round((float)($row['debit'] ?? 0), 2);
            $c = round((float)($row['credit'] ?? 0), 2);
            if (in_array($type, ['receive', 'discount', 'write_off'], true) && abs($c - $amount) < 0.01) {
                return $row;
            }
            if ($type === 'payment' && abs($d - $amount) < 0.01) {
                return $row;
            }
        }

        return $rows[0] ?? null;
    }

    private function ledgerAmountMatchesType(array $row, string $type, float $amount): bool
    {
        $d = round((float)($row['debit'] ?? 0), 2);
        $c = round((float)($row['credit'] ?? 0), 2);
        if (in_array($type, ['receive', 'discount', 'write_off'], true)) {
            return abs($c - $amount) < 0.01 && $d < 0.01;
        }
        if ($type === 'payment') {
            return abs($d - $amount) < 0.01 && $c < 0.01;
        }

        return false;
    }

    private function expectedLedgerSideLabel(string $type): string
    {
        return in_array($type, ['receive', 'discount', 'write_off'], true) ? 'credit' : 'debit';
    }

    private function hasReversalLedgerRow(array $rows): bool
    {
        foreach ($rows as $row) {
            if (($row['reference_type'] ?? '') === 'reversal') {
                return true;
            }
        }

        return false;
    }

    private function getLastRunningBalance(int $customerId): float
    {
        if ($customerId <= 0) {
            return 0.0;
        }
        try {
            $this->db->query("
                SELECT COALESCE(running_balance, 0) AS bal
                FROM customer_ledger
                WHERE customer_id = :cid
                ORDER BY id DESC
                LIMIT 1
            ");
            $this->db->bind(':cid', $customerId);

            return (float)($this->db->single()['bal'] ?? 0);
        } catch (Exception $e) {
            return 0.0;
        }
    }

    private function journalIsBalanced(?array $entry): bool
    {
        if (!$entry || empty($entry['lines'])) {
            return false;
        }
        $debit = $credit = 0.0;
        foreach ($entry['lines'] as $line) {
            $debit += (float)($line['debit'] ?? 0);
            $credit += (float)($line['credit'] ?? 0);
        }

        return abs($debit - $credit) < 0.02 && $debit > 0;
    }

    private function item(string $id, string $type, string $title, string $expected, string $status, ?string $detail = null): array
    {
        return [
            'id'       => $id,
            'type'     => $type,
            'title'    => $title,
            'expected' => $expected,
            'status'   => $status,
            'detail'   => $detail ?? '',
        ];
    }

    private function scalarCount(string $sql, array $bind = []): int
    {
        try {
            $this->db->query($sql);
            foreach ($bind as $k => $v) {
                $this->db->bind($k, $v);
            }

            return (int)($this->db->single()['c'] ?? 0);
        } catch (Exception $e) {
            return -1;
        }
    }

    private function money(float $n): string
    {
        return 'Tk ' . number_format($n, 2);
    }
}