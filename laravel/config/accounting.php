<?php

/**
 * Accounting Configuration — P2-1.
 *
 * Mirrors the legacy config/config.php accounting constants:
 *   - PERIOD_CLOSE_ADMIN_OVERRIDE (default false)
 *   - GL_RECONCILIATION_TOLERANCE (default 0.02)
 *
 * All values are env-overridable.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Period Close Admin Override
    |--------------------------------------------------------------------------
    | When true, admin + superadmin users can post journal entries to closed
    | accounting periods (posting_date <= closed_through_date). The override
    | is logged to user_audit_log for audit trail.
    |
    | When false, ALL users (including admin) are blocked from posting to
    | closed periods — the period must be reopened first.
    |
    | Legacy: PERIOD_CLOSE_ADMIN_OVERRIDE=false (env override)
    */
    'period_close_admin_override' => (bool) env('PERIOD_CLOSE_ADMIN_OVERRIDE', false),

    /*
    |--------------------------------------------------------------------------
    | GL Reconciliation Tolerance
    |--------------------------------------------------------------------------
    | Maximum acceptable difference (in Taka) between sub-ledger and GL
    | control account balances during reconciliation. Differences below
    | this threshold are considered "balanced" (rounding noise).
    |
    | Legacy: GL_RECONCILIATION_TOLERANCE=0.02
    */
    'gl_reconciliation_tolerance' => (float) env('GL_RECONCILIATION_TOLERANCE', 0.02),

    /*
    |--------------------------------------------------------------------------
    | Base Currency Code
    |--------------------------------------------------------------------------
    | The default currency for financial reports and CSV exports. The system
    | is multi-currency-capable at the schema level but in practice every
    | branch operates in BDT (Bangladeshi Taka). Exports surface this code
    | in a `Currency` column so downstream consumers (auditors, Excel
    | analysts) can disambiguate amounts without guessing.
    |
    | REPORTS-AUDIT-6 (G-236 / csv-export.md G15): added so the financial
    | CSV exports can call `config('accounting.currency', 'BDT')` instead
    | of hardcoding the string in every builder method.
    */
    'currency' => env('ACCOUNTING_CURRENCY', 'BDT'),

];
