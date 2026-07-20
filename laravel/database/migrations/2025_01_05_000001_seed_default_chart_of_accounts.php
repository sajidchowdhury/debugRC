<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9.1 — Seed Default Chart of Accounts for PostgreSQL.
 *
 * Creates the full hierarchical CoA with all 7 critical natures + extended natures.
 * Idempotent: only inserts ledgers that don't already exist (by ledger_code).
 *
 * Structure:
 *   Level 1: Main groups (Asset, Liability, Equity, Income, Expense)
 *   Level 2: Sub-groups (Current Assets, Current Liabilities, etc.)
 *   Level 3: Control accounts + transactional ledgers (with natures)
 *
 * Run with: php artisan chart:seed (or php artisan migrate)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Only seed if the ledgers table is empty.
        $count = DB::table('ledgers')->count();
        if ($count > 0) {
            return;
        }

        $now = now();

        // Helper to insert a ledger and return its ID.
        $insert = function (array $data) use ($now) {
            return DB::table('ledgers')->insertGetId(array_merge($data, [
                'is_active' => true,
                'is_control_account' => false,
                'opening_balance' => 0,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        };

        // ============================================================
        // Level 1: Main Groups
        // ============================================================
        $assetId = $insert(['ledger_code' => 'L-0001', 'ledger_name' => 'ASSETS', 'parent_id' => null, 'account_type' => 'Asset', 'ledger_nature' => null, 'sort_order' => 10]);
        $liabilityId = $insert(['ledger_code' => 'L-0002', 'ledger_name' => 'LIABILITIES', 'parent_id' => null, 'account_type' => 'Liability', 'ledger_nature' => null, 'sort_order' => 20]);
        $equityId = $insert(['ledger_code' => 'L-0003', 'ledger_name' => 'EQUITY', 'parent_id' => null, 'account_type' => 'Equity', 'ledger_nature' => null, 'sort_order' => 30]);
        $incomeId = $insert(['ledger_code' => 'L-0004', 'ledger_name' => 'INCOME', 'parent_id' => null, 'account_type' => 'Income', 'ledger_nature' => null, 'sort_order' => 40]);
        $expenseId = $insert(['ledger_code' => 'L-0005', 'ledger_name' => 'EXPENSES', 'parent_id' => null, 'account_type' => 'Expense', 'ledger_nature' => null, 'sort_order' => 50]);

        // ============================================================
        // Level 2: Sub-Groups
        // ============================================================
        $currentAssetsId = $insert(['ledger_code' => 'L-0100', 'ledger_name' => 'Current Assets', 'parent_id' => $assetId, 'account_type' => 'Asset', 'ledger_nature' => null, 'sort_order' => 110]);
        $fixedAssetsId = $insert(['ledger_code' => 'L-0200', 'ledger_name' => 'Fixed Assets', 'parent_id' => $assetId, 'account_type' => 'Asset', 'ledger_nature' => null, 'sort_order' => 120]);
        $currentLiabilitiesId = $insert(['ledger_code' => 'L-0300', 'ledger_name' => 'Current Liabilities', 'parent_id' => $liabilityId, 'account_type' => 'Liability', 'ledger_nature' => null, 'sort_order' => 210]);
        $longTermLiabilitiesId = $insert(['ledger_code' => 'L-0400', 'ledger_name' => 'Long Term Liabilities', 'parent_id' => $liabilityId, 'account_type' => 'Liability', 'ledger_nature' => null, 'sort_order' => 220]);
        $ownersEquityId = $insert(['ledger_code' => 'L-0500', 'ledger_name' => "Owner's Equity", 'parent_id' => $equityId, 'account_type' => 'Equity', 'ledger_nature' => null, 'sort_order' => 310]);
        $salesRevenueGroupId = $insert(['ledger_code' => 'L-0700', 'ledger_name' => 'Sales Revenue', 'parent_id' => $incomeId, 'account_type' => 'Income', 'ledger_nature' => null, 'sort_order' => 410]);
        $otherIncomeGroupId = $insert(['ledger_code' => 'L-0800', 'ledger_name' => 'Other Income', 'parent_id' => $incomeId, 'account_type' => 'Income', 'ledger_nature' => null, 'sort_order' => 420]);
        $adminExpensesId = $insert(['ledger_code' => 'L-0900', 'ledger_name' => 'Administrative Expenses', 'parent_id' => $expenseId, 'account_type' => 'Expense', 'ledger_nature' => null, 'sort_order' => 610]);
        $sellingExpensesId = $insert(['ledger_code' => 'L-1000', 'ledger_name' => 'Selling & Distribution Expenses', 'parent_id' => $expenseId, 'account_type' => 'Expense', 'ledger_nature' => null, 'sort_order' => 620]);
        $financialExpensesId = $insert(['ledger_code' => 'L-1100', 'ledger_name' => 'Financial Expenses', 'parent_id' => $expenseId, 'account_type' => 'Expense', 'ledger_nature' => null, 'sort_order' => 630]);

        // ============================================================
        // Level 3: Critical Nature Ledgers (the 7 critical natures)
        // ============================================================

        // 1. cash_bank (CRITICAL)
        $insert(['ledger_code' => 'L-0101', 'ledger_name' => 'Cash in Hand', 'parent_id' => $currentAssetsId, 'account_type' => 'Asset', 'ledger_nature' => 'cash_bank', 'sort_order' => 1110]);

        // 2. ar (CRITICAL) — Accounts Receivable control account
        $insert(['ledger_code' => 'L-0103', 'ledger_name' => 'Accounts Receivable (Customers)', 'parent_id' => $currentAssetsId, 'account_type' => 'Asset', 'ledger_nature' => 'ar', 'is_control_account' => true, 'control_account_type' => 'customer', 'sort_order' => 1130]);

        // 3. inventory (CRITICAL)
        $insert(['ledger_code' => 'L-0104', 'ledger_name' => 'Inventory / Stock', 'parent_id' => $currentAssetsId, 'account_type' => 'Asset', 'ledger_nature' => 'inventory', 'sort_order' => 1140]);

        // 4. ap (CRITICAL) — Accounts Payable control account
        $insert(['ledger_code' => 'L-0301', 'ledger_name' => 'Accounts Payable (Suppliers)', 'parent_id' => $currentLiabilitiesId, 'account_type' => 'Liability', 'ledger_nature' => 'ap', 'is_control_account' => true, 'control_account_type' => 'supplier', 'sort_order' => 2110]);

        // 5. sales_revenue (CRITICAL)
        $insert(['ledger_code' => 'L-0701', 'ledger_name' => 'Sales - Local', 'parent_id' => $salesRevenueGroupId, 'account_type' => 'Income', 'ledger_nature' => 'sales_revenue', 'sort_order' => 4110]);

        // 6. cogs (CRITICAL)
        $insert(['ledger_code' => 'L-0501', 'ledger_name' => 'Cost of Goods Sold', 'parent_id' => $expenseId, 'account_type' => 'Expense', 'ledger_nature' => 'cogs', 'sort_order' => 510]);

        // 7. retained_earnings (CRITICAL)
        $insert(['ledger_code' => 'L-0600', 'ledger_name' => 'Retained Earnings', 'parent_id' => $equityId, 'account_type' => 'Equity', 'ledger_nature' => 'retained_earnings', 'sort_order' => 320]);

        // ============================================================
        // Level 3: Extended Nature Ledgers
        // ============================================================

        // sales_return (contra-revenue)
        $insert(['ledger_code' => 'L-0702', 'ledger_name' => 'Sales Return & Allowances', 'parent_id' => $salesRevenueGroupId, 'account_type' => 'Income', 'ledger_nature' => 'sales_return', 'sort_order' => 4120]);

        // sales_discount (contra-revenue)
        $insert(['ledger_code' => 'L-0703', 'ledger_name' => 'Sales Discount Allowed', 'parent_id' => $salesRevenueGroupId, 'account_type' => 'Expense', 'ledger_nature' => 'sales_discount', 'sort_order' => 4130]);

        // transport_revenue
        $insert(['ledger_code' => 'L-0801', 'ledger_name' => 'Transport Revenue', 'parent_id' => $otherIncomeGroupId, 'account_type' => 'Income', 'ledger_nature' => 'transport_revenue', 'sort_order' => 4210]);

        // inventory_shrinkage
        $insert(['ledger_code' => 'L-0502', 'ledger_name' => 'Inventory Shrinkage / Loss', 'parent_id' => $expenseId, 'account_type' => 'Expense', 'ledger_nature' => 'inventory_shrinkage', 'sort_order' => 520]);

        // inventory_surplus
        $insert(['ledger_code' => 'L-0802', 'ledger_name' => 'Inventory Surplus / Gain', 'parent_id' => $otherIncomeGroupId, 'account_type' => 'Income', 'ledger_nature' => 'inventory_surplus', 'sort_order' => 4220]);

        // damage_loss
        $insert(['ledger_code' => 'L-0503', 'ledger_name' => 'Damage Loss', 'parent_id' => $expenseId, 'account_type' => 'Expense', 'ledger_nature' => 'damage_loss', 'sort_order' => 530]);

        // employee_payable (control account)
        $insert(['ledger_code' => 'L-0302', 'ledger_name' => 'Employee Payable', 'parent_id' => $currentLiabilitiesId, 'account_type' => 'Liability', 'ledger_nature' => 'employee_payable', 'is_control_account' => true, 'control_account_type' => 'employee', 'sort_order' => 2120]);

        // interbranch_receivable
        $insert(['ledger_code' => 'L-0105', 'ledger_name' => 'Due from Branches', 'parent_id' => $currentAssetsId, 'account_type' => 'Asset', 'ledger_nature' => 'interbranch_receivable', 'sort_order' => 1150]);

        // interbranch_payable
        $insert(['ledger_code' => 'L-0303', 'ledger_name' => 'Due to Branches', 'parent_id' => $currentLiabilitiesId, 'account_type' => 'Liability', 'ledger_nature' => 'interbranch_payable', 'sort_order' => 2130]);

        // other_income
        $insert(['ledger_code' => 'L-0803', 'ledger_name' => 'Other Income', 'parent_id' => $otherIncomeGroupId, 'account_type' => 'Income', 'ledger_nature' => 'other_income', 'sort_order' => 4230]);

        // operating_expense
        $insert(['ledger_code' => 'L-0901', 'ledger_name' => 'General Operating Expense', 'parent_id' => $adminExpensesId, 'account_type' => 'Expense', 'ledger_nature' => 'operating_expense', 'sort_order' => 6110]);

        // salary_expense
        $insert(['ledger_code' => 'L-0902', 'ledger_name' => 'Salary Expense', 'parent_id' => $adminExpensesId, 'account_type' => 'Expense', 'ledger_nature' => 'salary_expense', 'sort_order' => 6120]);

        // finance_cost
        $insert(['ledger_code' => 'L-1101', 'ledger_name' => 'Bank Charges & Interest', 'parent_id' => $financialExpensesId, 'account_type' => 'Expense', 'ledger_nature' => 'finance_cost', 'sort_order' => 6310]);
    }

    public function down(): void
    {
        // Only remove seeded ledgers (by code prefix L-0 to L-1).
        DB::table('ledgers')->where('ledger_code', 'LIKE', 'L-%')->delete();
    }
};
