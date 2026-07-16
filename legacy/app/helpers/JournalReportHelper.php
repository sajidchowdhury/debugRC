<?php
// app/helpers/JournalReportHelper.php — shared GL report utilities

class JournalReportHelper
{
    /**
     * Compute display balance from debit/credit totals and normal balance side.
     *
     * @return array{net: float, balance: float, balance_side: string, signed_balance: float}
     */
    public static function computeBalance(float $debit, float $credit, string $normalBalance): array
    {
        $net = round($debit - $credit, 2);

        if ($normalBalance === 'debit') {
            $signed = $net;
            $side = $net >= 0 ? 'Dr' : 'Cr';
        } else {
            $signed = -$net;
            $side = $net <= 0 ? 'Cr' : 'Dr';
        }

        return [
            'net'            => $net,
            'balance'        => round(abs($signed), 2),
            'balance_side'   => $side,
            'signed_balance' => round($signed, 2),
        ];
    }

    public static function referenceLabel(?string $type): string
    {
        if ($type === null || $type === '') {
            return '—';
        }

        return ucwords(str_replace('_', ' ', $type));
    }

    /**
     * Resolve a detail-page URL for a journal reference, or null when unknown.
     */
    public static function referenceUrl(?string $type, $referenceId): ?string
    {
        $id = (int)$referenceId;
        if ($type === null || $type === '' || $id <= 0) {
            return null;
        }

        $base = defined('BASE_URL') ? BASE_URL : '/';

        $routes = [
            'sales_invoice'            => 'sales/show/',
            'sales_invoice_adjustment' => 'sales/show/',
            'customer_payment'         => 'CustomerTransaction/details/',
            'supplier_payment'         => 'SupplierTransaction/details/',
            'employee_transaction'     => 'EmployeeTransaction/details/',
            'other_expense'            => 'OtherExpense/details/',
            'other_income'             => 'OtherIncome/details/',
            'money_transfer'           => 'MoneyTransfer/details/',
            'purchase_receive'         => 'PurchaseReceive/details/',
            'purchase_return'          => 'PurchaseReturn/details/',
            'sales_return'             => 'SalesReturn/details/',
            'sales_challan'            => 'challan/details/',
            'damage'                   => 'Damage/details/',
            'stock_adjustment'         => 'StockAdjustment/details/',
            'stock_take'               => 'StockTake/details/',
            'branch_demand'            => 'BranchDemand/details/',
            'branch_demand_settlement' => 'BranchDemand/details/',
            'warehouse_transfer'       => 'WarehouseTransfer/details/',
            'manual'                   => 'ManualJournal/details/',
        ];

        if (!isset($routes[$type])) {
            return null;
        }

        return $base . $routes[$type] . $id;
    }

    /** @return array<string, string> */
    public static function referenceTypeOptions(): array
    {
        return [
            'customer_payment'         => 'Customer payment',
            'supplier_payment'         => 'Supplier payment',
            'employee_transaction'     => 'Employee transaction',
            'other_expense'            => 'Other expense',
            'other_income'             => 'Other income',
            'money_transfer'           => 'Money transfer',
            'sales_invoice'            => 'Sales invoice',
            'sales_invoice_adjustment' => 'Sales invoice adjustment',
            'sales_challan'            => 'Sales challan (COGS)',
            'sales_return'             => 'Sales return',
            'purchase_receive'         => 'Purchase receive',
            'purchase_return'          => 'Purchase return',
            'stock_take'               => 'Stock take',
            'stock_adjustment'         => 'Stock adjustment',
            'damage'                   => 'Damage write-off',
            'branch_demand'            => 'Branch demand',
            'branch_demand_settlement' => 'Branch demand settlement',
            'warehouse_transfer'       => 'Warehouse transfer',
            'manual'                   => 'Manual journal',
            'reversal'                 => 'Reversal',
        ];
    }

    public static function formatAmount(float $amount): string
    {
        return $amount > 0 ? number_format($amount, 2) : '—';
    }
}
