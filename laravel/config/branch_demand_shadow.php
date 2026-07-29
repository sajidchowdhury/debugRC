<?php

/**
 * Branch Demand Shadow Mode Configuration — Phase 10.
 *
 * Shadow mode enables the Branch Demand module to run alongside the
 * legacy BranchIntercompanyService (MySQL) in parallel. Every demand
 * operation is executed by both systems, and the results are compared
 * (stock movements, GL, ledger, settlement).
 *
 * Zero diffs for 7 consecutive days → cutover readiness.
 *
 * Shadow mode has three operational states:
 *
 *   1. OFF      — Normal operation. Legacy and Laravel run independently.
 *                  No comparison is performed.
 *
 *   2. PASSIVE  — Laravel is the primary system. After each demand
 *                  operation, the legacy system also processes the same
 *                  operation (if available). Results are compared and
 *                  logged to shadow_demand_comparisons table. Diffs
 *                  trigger alerts but don't block operations.
 *
 *   3. ACTIVE   — Both systems process every operation simultaneously.
 *                  The legacy system's result is treated as the "gold"
 *                  reference. If Laravel's result differs, an alert is
 *                  raised and the diff is logged. Operations continue
 *                  but the comparison dashboard highlights mismatches.
 *
 * Transition plan:
 *   OFF → PASSIVE:  After Phase 1-8 code review sign-off
 *   PASSIVE → ACTIVE: After 3 days of zero diffs in passive mode
 *   ACTIVE → CUTOVER: After 7 consecutive days of zero diffs
 *   ACTIVE → OFF:    If critical diffs are found (rollback)
 */
return [

    /**
     * Master toggle. Set to true to enable shadow mode infrastructure.
     * When false, the shadow service and dashboard routes are still
     * available but comparison runs are skipped.
     */
    'enabled' => env('BRANCH_DEMAND_SHADOW_ENABLED', false),

    /**
     * Operational state: 'off', 'passive', 'active'.
     *
     * 'off'      — No comparison. Normal single-system operation.
     * 'passive'  — Laravel primary, legacy comparison after each op.
     * 'active'   — Dual-write, comparison on every operation.
     */
    'mode' => env('BRANCH_DEMAND_SHADOW_MODE', 'off'),

    /**
     * Legacy MySQL connection for reading legacy demand data.
     * Uses the same archive connection (read-only).
     */
    'legacy_connection' => env('BRANCH_DEMAND_SHADOW_LEGACY_CONNECTION', 'archive'),

    /**
     * Cutover readiness thresholds.
     *
     * consecutive_days_zero_diff: Number of consecutive days with zero
     *   diffs required before cutover can proceed. Default: 7.
     *
     * max_tolerance_amount: Maximum acceptable amount difference between
     *   legacy and Laravel demand totals. Default: 0.01.
     *
     * max_tolerance_settlement: Maximum acceptable settlement difference.
     *   Default: 0.01.
     */
    'cutover' => [
        'consecutive_days_zero_diff' => env('BRANCH_DEMAND_CUTOVER_DAYS', 7),
        'max_tolerance_amount'       => 0.01,
        'max_tolerance_settlement'   => 0.01,
    ],

    /**
     * Comparison scope — which aspects to compare.
     *
     * demand_header:  Compare demand status, total_value, settlement_amount
     * gl_postings:    Compare journal entries (debit/credit amounts)
     * ledger:         Compare branch_ledger running balance
     * settlements:    Compare settlement amounts and FIFO ordering
     * stock_movements: Compare stock_transactions (qty, rate, warehouse)
     * repricing:      Compare repricing adjustments
     */
    'comparison_scope' => [
        'demand_header'    => true,
        'gl_postings'      => true,
        'ledger'           => true,
        'settlements'      => true,
        'stock_movements'  => true,
        'repricing'        => true,
    ],

    /**
     * Alert configuration — when diffs are detected.
     */
    'alerts' => [
        'log_channel'        => env('BRANCH_DEMAND_SHADOW_LOG_CHANNEL', 'shadow'),
        'notify_email'       => env('BRANCH_DEMAND_SHADOW_EMAIL', ''),
        'notify_on_critical' => true,
    ],

    /**
     * Comparison run schedule.
     */
    'schedule' => [
        'auto_compare_after_ops' => true,
        'scheduled_compare'      => env('BRANCH_DEMAND_SHADOW_SCHEDULED_COMPARE', 'daily'),
        'batch_size'             => 100,
    ],

    /**
     * Dashboard settings.
     */
    'dashboard' => [
        'show_legacy_data'         => true,
        'diff_highlight_tolerance' => 0.001,
    ],

    /**
     * Retention settings — how long to keep comparison records.
     */
    'retention' => [
        'comparison_retention_days' => 90,
        'zero_diff_retention_days'  => 30,
    ],
];
