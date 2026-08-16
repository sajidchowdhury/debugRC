<?php

/**
 * Fiscal Year Configuration — canonical source of truth.
 *
 * This config drives:
 *   - Session 1 migration: which tables get the `fiscal_year_id` column
 *   - Session 1 backfill: which date column (or parent-table JOIN) to use
 *   - Session 2: which models get the `BelongsToFiscalYear` trait
 *   - Session 4: which partitioned tables get DETACH on year-end close
 *
 * Structure per entry:
 *   - table:         physical table name
 *   - date_column:   column used for backfill (NULL for child tables that JOIN via parent)
 *   - partitioned:   whether the table is RANGE-partitioned (affects FK strategy + Session 4 detach)
 *   - parent:        for child tables only — [table, fk_column, date_column] of the parent
 *
 * Tables EXCLUDED from this list (intentionally):
 *   - Master data: products, customers, suppliers, employees, branches, warehouses,
 *     ledgers, users, roles, permissions — these are NOT fiscal-year-scoped.
 *   - Audit logs: user_audit_log, financial_audit_log, journal_posting_logs,
 *     branch_demand_audit_log, stock_take_audit_log, stock_adjustment_audit_log —
 *     these must remain queryable across fiscal years for compliance, so they are
 *     NOT scoped and NOT detached on year-end close. (See plan risk register.)
 *   - Fiscal-control tables: fiscal_years, fiscal_periods, period_close_log,
 *     budgets, consolidation_runs — these already have fiscal_year_id or are
 *     meta-tables about fiscal years themselves.
 *   - Materialized views, queue tables, cache tables, sessions.
 *
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md Session 1
 */

return [

    // ── Operational tables that carry fiscal_year_id ──────────────────────
    'tables' => [

        // ── Sales & receivables ───────────────────────────────────────────
        [
            'table'        => 'sales_invoices',
            'date_column'  => 'invoice_date',
            'partitioned'  => true,
        ],
        [
            'table'        => 'sales_invoice_items',
            'date_column'  => null,
            'partitioned'  => false,
            'parent'       => ['sales_invoices', 'sales_invoice_id', 'invoice_date'],
        ],
        [
            'table'        => 'sales_challans',
            'date_column'  => 'challan_date',
            'partitioned'  => true,
        ],
        [
            'table'        => 'sales_challan_items',
            'date_column'  => null,
            'partitioned'  => false,
            'parent'       => ['sales_challans', 'sales_challan_id', 'challan_date'],
        ],
        [
            'table'        => 'sales_returns',
            'date_column'  => 'return_date',
            'partitioned'  => true,
        ],
        [
            'table'        => 'sales_return_items',
            'date_column'  => null,
            'partitioned'  => false,
            'parent'       => ['sales_returns', 'sales_return_id', 'return_date'],
        ],
        [
            'table'        => 'customer_payments',
            'date_column'  => 'payment_date',
            'partitioned'  => true,
        ],
        [
            'table'        => 'customer_ledger',
            'date_column'  => 'transaction_date',
            'partitioned'  => true,
        ],

        // ── Purchases & payables ──────────────────────────────────────────
        [
            'table'        => 'purchase_orders',
            'date_column'  => 'po_date',
            'partitioned'  => false,
        ],
        [
            'table'        => 'purchase_order_items',
            'date_column'  => null,
            'partitioned'  => false,
            'parent'       => ['purchase_orders', 'purchase_order_id', 'po_date'],
        ],
        [
            'table'        => 'purchase_receives',
            'date_column'  => 'receive_date',
            'partitioned'  => true,
        ],
        [
            'table'        => 'purchase_receive_items',
            'date_column'  => null,
            'partitioned'  => false,
            'parent'       => ['purchase_receives', 'purchase_receive_id', 'receive_date'],
        ],
        [
            'table'        => 'purchase_returns',
            'date_column'  => 'return_date',
            'partitioned'  => true,
        ],
        [
            'table'        => 'purchase_return_items',
            'date_column'  => null,
            'partitioned'  => false,
            'parent'       => ['purchase_returns', 'purchase_return_id', 'return_date'],
        ],
        [
            'table'        => 'supplier_payments',
            'date_column'  => 'payment_date',
            'partitioned'  => true,
        ],
        [
            'table'        => 'supplier_ledger',
            'date_column'  => 'transaction_date',
            'partitioned'  => true,
        ],

        // ── Inventory ─────────────────────────────────────────────────────
        [
            'table'        => 'stock_transactions',
            'date_column'  => 'transaction_date',
            'partitioned'  => true,
        ],
        [
            'table'        => 'stock_adjustments',
            'date_column'  => 'adjustment_date',
            'partitioned'  => true,
        ],
        [
            'table'        => 'stock_adjustment_items',
            'date_column'  => null,
            'partitioned'  => false,
            'parent'       => ['stock_adjustments', 'stock_adjustment_id', 'adjustment_date'],
        ],
        [
            'table'        => 'stock_take_sessions',
            'date_column'  => 'session_date',
            'partitioned'  => true,
        ],
        [
            'table'        => 'stock_take_warehouses',
            'date_column'  => null,
            'partitioned'  => false,
            'parent'       => ['stock_take_sessions', 'stock_take_session_id', 'session_date'],
        ],
        [
            'table'        => 'warehouse_transfers',
            'date_column'  => 'transfer_date',
            'partitioned'  => true,
        ],
        [
            'table'        => 'warehouse_transfer_items',
            'date_column'  => null,
            'partitioned'  => false,
            'parent'       => ['warehouse_transfers', 'warehouse_transfer_id', 'transfer_date'],
        ],
        [
            'table'        => 'damage_invoices',
            'date_column'  => 'damage_date',
            'partitioned'  => true,
        ],
        [
            'table'        => 'damage_invoice_items',
            'date_column'  => null,
            'partitioned'  => false,
            'parent'       => ['damage_invoices', 'damage_invoice_id', 'damage_date'],
        ],

        // ── Inter-branch ──────────────────────────────────────────────────
        [
            'table'        => 'branch_demands',
            'date_column'  => 'demand_date',
            'partitioned'  => false,
        ],
        [
            'table'        => 'branch_demand_items',
            'date_column'  => null,
            'partitioned'  => false,
            'parent'       => ['branch_demands', 'branch_demand_id', 'demand_date'],
        ],
        [
            'table'        => 'branch_demand_repricing',
            'date_column'  => 'created_at',
            'partitioned'  => false,
        ],
        [
            'table'        => 'branch_ledger',
            'date_column'  => 'transaction_date',
            'partitioned'  => true,
        ],

        // ── Accounting ────────────────────────────────────────────────────
        [
            'table'        => 'journal_entries',
            'date_column'  => 'entry_date',
            'partitioned'  => true,
        ],
        [
            'table'        => 'journal_lines',
            'date_column'  => null,
            'partitioned'  => false,
            'parent'       => ['journal_entries', 'journal_entry_id', 'entry_date'],
        ],
        [
            'table'        => 'manual_journals',
            'date_column'  => 'journal_date',
            'partitioned'  => true,
        ],
        [
            'table'        => 'other_incomes',
            'date_column'  => 'income_date',
            'partitioned'  => true,
        ],
        [
            'table'        => 'other_expenses',
            'date_column'  => 'expense_date',
            'partitioned'  => true,
        ],
        [
            'table'        => 'money_transfers',
            'date_column'  => 'transfer_date',
            'partitioned'  => true,
        ],
        [
            'table'        => 'employee_transactions',
            'date_column'  => 'transaction_date',
            'partitioned'  => true,
        ],
    ],

    // ── Partitioned tables eligible for DETACH on year-end close ──────────
    // Subset of the above (partitioned === true only). Session 4 uses this.
    // Audit logs are deliberately EXCLUDED (must remain queryable for compliance).
    'partitioned_tables' => [
        'sales_invoices',
        'sales_challans',
        'sales_returns',
        'customer_payments',
        'customer_ledger',
        'purchase_receives',
        'purchase_returns',
        'supplier_payments',
        'supplier_ledger',
        'stock_transactions',
        'stock_adjustments',
        'stock_take_sessions',
        'warehouse_transfers',
        'damage_invoices',
        'branch_ledger',
        'journal_entries',
        'manual_journals',
        'other_incomes',
        'other_expenses',
        'money_transfers',
        'employee_transactions',
    ],
];
