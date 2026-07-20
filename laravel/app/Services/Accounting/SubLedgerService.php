<?php

namespace App\Services\Accounting;

use App\Models\CustomerLedger;
use App\Models\SupplierLedger;
use App\Models\EmployeeLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sub-Ledger Service — Phase 9.3.
 *
 * Centralizes the dual-write pattern: every business transaction that touches
 * a control account (AR, AP, Employee Payable) in the GL also writes a row
 * to the corresponding sub-ledger. This service ensures the dual-write is
 * consistent and the running balance is always correct.
 *
 * The running balance formula:
 *   customer_ledger: balance = prev + debit - credit (debit = customer owes more)
 *   supplier_ledger: balance = prev + credit - debit (credit = we owe more)
 *   employee_ledger: balance = prev + credit - debit (credit = we owe more)
 *
 * All methods accept a journal_entry_id parameter so the sub-ledger row
 * links back to the GL journal entry that caused it. This enables the
 * reconciliation hub to trace any sub-ledger balance back to its GL source.
 */
class SubLedgerService
{
    /**
     * Post a customer_ledger entry (AR sub-ledger).
     *
     * @param array $data {
     *     customer_id: int,
     *     branch_id: int|null,
     *     transaction_date: string (Y-m-d),
     *     transaction_type: string,
     *     reference_type: string|null,
     *     reference_id: int|null,
     *     debit: float (customer owes more — e.g. invoice),
     *     credit: float (customer owes less — e.g. payment, return),
     *     description: string|null,
     *     journal_entry_id: int|null,
     *     created_by: int|null,
     * }
     * @return int The customer_ledger row ID.
     */
    public function postCustomerLedgerEntry(array $data): int
    {
        $customerId = (int) $data['customer_id'];
        $debit = (float) ($data['debit'] ?? 0);
        $credit = (float) ($data['credit'] ?? 0);

        // Get current balance (from last non-reversed entry).
        $currentBalance = CustomerLedger::getBalance($customerId);
        $newBalance = $currentBalance + $debit - $credit;

        return DB::table('customer_ledger')->insertGetId([
            'customer_id' => $customerId,
            'branch_id' => $data['branch_id'] ?? null,
            'transaction_date' => $data['transaction_date'] ?? now()->format('Y-m-d'),
            'transaction_type' => $data['transaction_type'],
            'reference_type' => $data['reference_type'] ?? null,
            'reference_id' => $data['reference_id'] ?? null,
            'debit' => $debit,
            'credit' => $credit,
            'balance' => round($newBalance, 2),
            'is_reversed' => false,
            'description' => $data['description'] ?? null,
            'journal_entry_id' => $data['journal_entry_id'] ?? null,
            'created_by' => $data['created_by'] ?? null,
            'created_at' => now(),
        ]);
    }

    /**
     * Post a supplier_ledger entry (AP sub-ledger).
     *
     * @param array $data {
     *     supplier_id: int,
     *     branch_id: int|null,
     *     transaction_date: string (Y-m-d),
     *     transaction_type: string,
     *     reference_type: string|null,
     *     reference_id: int|null,
     *     debit: float (we owe less — e.g. payment, return),
     *     credit: float (we owe more — e.g. GRN),
     *     description: string|null,
     *     journal_entry_id: int|null,
     *     created_by: int|null,
     * }
     * @return int The supplier_ledger row ID.
     */
    public function postSupplierLedgerEntry(array $data): int
    {
        $supplierId = (int) $data['supplier_id'];
        $debit = (float) ($data['debit'] ?? 0);
        $credit = (float) ($data['credit'] ?? 0);

        $currentBalance = SupplierLedger::getBalance($supplierId);
        $newBalance = $currentBalance + $credit - $debit;

        return DB::table('supplier_ledger')->insertGetId([
            'supplier_id' => $supplierId,
            'branch_id' => $data['branch_id'] ?? null,
            'transaction_date' => $data['transaction_date'] ?? now()->format('Y-m-d'),
            'transaction_type' => $data['transaction_type'],
            'reference_type' => $data['reference_type'] ?? null,
            'reference_id' => $data['reference_id'] ?? null,
            'debit' => $debit,
            'credit' => $credit,
            'balance' => round($newBalance, 2),
            'is_reversed' => false,
            'description' => $data['description'] ?? null,
            'journal_entry_id' => $data['journal_entry_id'] ?? null,
            'created_by' => $data['created_by'] ?? null,
            'created_at' => now(),
        ]);
    }

    /**
     * Post an employee_ledger entry (Employee Payable sub-ledger).
     *
     * @param array $data {
     *     employee_id: int,
     *     branch_id: int|null,
     *     transaction_date: string (Y-m-d),
     *     transaction_type: string (advance|loan|repayment|salary|deduction|adjustment),
     *     reference_type: string|null,
     *     reference_id: int|null,
     *     debit: float (advance given / deduction — reduces payable),
     *     credit: float (salary earned / loan — increases payable),
     *     description: string|null,
     *     journal_entry_id: int|null,
     *     created_by: int|null,
     * }
     * @return int The employee_ledger row ID.
     */
    public function postEmployeeLedgerEntry(array $data): int
    {
        $employeeId = (int) $data['employee_id'];
        $debit = (float) ($data['debit'] ?? 0);
        $credit = (float) ($data['credit'] ?? 0);

        $currentBalance = EmployeeLedger::getBalance($employeeId);
        $newBalance = $currentBalance + $credit - $debit;

        return DB::table('employee_ledger')->insertGetId([
            'employee_id' => $employeeId,
            'branch_id' => $data['branch_id'] ?? null,
            'transaction_date' => $data['transaction_date'] ?? now()->format('Y-m-d'),
            'transaction_type' => $data['transaction_type'],
            'reference_type' => $data['reference_type'] ?? null,
            'reference_id' => $data['reference_id'] ?? null,
            'debit' => $debit,
            'credit' => $credit,
            'balance' => round($newBalance, 2),
            'is_reversed' => false,
            'description' => $data['description'] ?? null,
            'journal_entry_id' => $data['journal_entry_id'] ?? null,
            'created_by' => $data['created_by'] ?? null,
            'created_at' => now(),
        ]);
    }

    /**
     * Reverse a customer_ledger entry (mark is_reversed, post opposite entry).
     *
     * @param int $ledgerId
     * @param int $reversedBy
     * @param string $reason
     * @return int The reversal entry ID.
     */
    public function reverseCustomerLedgerEntry(int $ledgerId, int $reversedBy, string $reason = ''): int
    {
        return DB::transaction(function () use ($ledgerId, $reversedBy, $reason) {
            $original = DB::table('customer_ledger')->where('id', $ledgerId)->lockForUpdate()->first();

            if (!$original) {
                throw new \RuntimeException("Customer ledger entry {$ledgerId} not found.");
            }
            if ($original->is_reversed) {
                throw new \RuntimeException("Entry already reversed.");
            }

            // Mark original as reversed.
            DB::table('customer_ledger')->where('id', $ledgerId)->update([
                'is_reversed' => true,
                'reversed_at' => now(),
                'reversed_by' => $reversedBy,
            ]);

            // Post the opposite entry (swap debit/credit).
            return $this->postCustomerLedgerEntry([
                'customer_id' => $original->customer_id,
                'branch_id' => $original->branch_id,
                'transaction_date' => now()->format('Y-m-d'),
                'transaction_type' => $original->transaction_type . '_reversal',
                'reference_type' => $original->reference_type,
                'reference_id' => $original->reference_id,
                'debit' => (float) $original->credit,
                'credit' => (float) $original->debit,
                'description' => 'Reversal: ' . ($original->description ?? '') . ($reason ? " — {$reason}" : ''),
                'created_by' => $reversedBy,
            ]);
        });
    }

    /**
     * Reverse a supplier_ledger entry.
     */
    public function reverseSupplierLedgerEntry(int $ledgerId, int $reversedBy, string $reason = ''): int
    {
        return DB::transaction(function () use ($ledgerId, $reversedBy, $reason) {
            $original = DB::table('supplier_ledger')->where('id', $ledgerId)->lockForUpdate()->first();

            if (!$original) throw new \RuntimeException("Supplier ledger entry {$ledgerId} not found.");
            if ($original->is_reversed) throw new \RuntimeException("Entry already reversed.");

            DB::table('supplier_ledger')->where('id', $ledgerId)->update([
                'is_reversed' => true, 'reversed_at' => now(), 'reversed_by' => $reversedBy,
            ]);

            return $this->postSupplierLedgerEntry([
                'supplier_id' => $original->supplier_id,
                'branch_id' => $original->branch_id,
                'transaction_date' => now()->format('Y-m-d'),
                'transaction_type' => $original->transaction_type . '_reversal',
                'reference_type' => $original->reference_type,
                'reference_id' => $original->reference_id,
                'debit' => (float) $original->credit,
                'credit' => (float) $original->debit,
                'description' => 'Reversal: ' . ($original->description ?? '') . ($reason ? " — {$reason}" : ''),
                'created_by' => $reversedBy,
            ]);
        });
    }

    /**
     * Reverse an employee_ledger entry.
     */
    public function reverseEmployeeLedgerEntry(int $ledgerId, int $reversedBy, string $reason = ''): int
    {
        return DB::transaction(function () use ($ledgerId, $reversedBy, $reason) {
            $original = DB::table('employee_ledger')->where('id', $ledgerId)->lockForUpdate()->first();

            if (!$original) throw new \RuntimeException("Employee ledger entry {$ledgerId} not found.");
            if ($original->is_reversed) throw new \RuntimeException("Entry already reversed.");

            DB::table('employee_ledger')->where('id', $ledgerId)->update([
                'is_reversed' => true, 'reversed_at' => now(), 'reversed_by' => $reversedBy,
            ]);

            return $this->postEmployeeLedgerEntry([
                'employee_id' => $original->employee_id,
                'branch_id' => $original->branch_id,
                'transaction_date' => now()->format('Y-m-d'),
                'transaction_type' => 'adjustment', // reversal type must be in CHECK constraint
                'reference_type' => $original->reference_type,
                'reference_id' => $original->reference_id,
                'debit' => (float) $original->credit,
                'credit' => (float) $original->debit,
                'description' => 'Reversal: ' . ($original->description ?? '') . ($reason ? " — {$reason}" : ''),
                'created_by' => $reversedBy,
            ]);
        });
    }

    // ============================================================
    // RECONCILIATION HELPERS
    // ============================================================

    /**
     * Get the total AR sub-ledger balance (all customers).
     */
    public function getTotalARBalance(): float
    {
        return (float) DB::table('customer_ledger')
            ->where('is_reversed', false)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) AS balance')
            ->value('balance');
    }

    /**
     * Get the total AP sub-ledger balance (all suppliers).
     */
    public function getTotalAPBalance(): float
    {
        return (float) DB::table('supplier_ledger')
            ->where('is_reversed', false)
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) AS balance')
            ->value('balance');
    }

    /**
     * Get the total Employee Payable sub-ledger balance.
     */
    public function getTotalEmployeePayableBalance(): float
    {
        return (float) DB::table('employee_ledger')
            ->where('is_reversed', false)
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) AS balance')
            ->value('balance');
    }

    /**
     * Reconcile all 3 sub-ledgers against their GL control accounts.
     *
     * @return array{ ar: array, ap: array, employee: array, all_match: bool }
     */
    public function reconcileAll(): array
    {
        $arSub = $this->getTotalARBalance();
        $arGl = $this->getGLControlBalance('ar');
        $arDrift = abs($arSub - $arGl);

        $apSub = $this->getTotalAPBalance();
        $apGl = $this->getGLControlBalance('ap');
        $apDrift = abs($apSub - $apGl);

        $empSub = $this->getTotalEmployeePayableBalance();
        $empGl = $this->getGLControlBalance('employee_payable');
        $empDrift = abs($empSub - ($empGl ?? 0));

        $tolerance = 0.02;

        return [
            'ar' => [
                'subledger' => $arSub, 'gl_control' => $arGl, 'drift' => $arDrift,
                'match' => $arDrift <= $tolerance,
            ],
            'ap' => [
                'subledger' => $apSub, 'gl_control' => $apGl, 'drift' => $apDrift,
                'match' => $apDrift <= $tolerance,
            ],
            'employee' => [
                'subledger' => $empSub, 'gl_control' => $empGl ?? 0, 'drift' => $empDrift,
                'match' => $empDrift <= $tolerance,
            ],
            'all_match' => $arDrift <= $tolerance && $apDrift <= $tolerance && $empDrift <= $tolerance,
        ];
    }

    /**
     * Get the GL balance for a control account nature.
     * AR: debit - credit. AP/Employee: credit - debit.
     */
    private function getGLControlBalance(string $nature): ?float
    {
        $ledger = DB::table('ledgers')
            ->where('ledger_nature', $nature)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        if (!$ledger) return null;

        $balance = (float) DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.ledger_id', $ledger->id)
            ->where('je.is_reversed', false)
            ->selectRaw('COALESCE(SUM(jl.debit) - SUM(jl.credit), 0) AS balance')
            ->value('balance');

        // For liability natures (ap, employee_payable), the control balance is credit - debit.
        if (in_array($nature, ['ap', 'employee_payable'])) {
            return -$balance;
        }

        return $balance;
    }
}
