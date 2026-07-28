<?php

/**
 * Shadow Mode Configuration — Phase 7.3.
 *
 * Shadow mode enables the WarehouseTransfer module to run alongside the
 * legacy system in parallel. Every transfer operation is executed by both
 * systems, and the results are compared (stock movements, GL, status).
 *
 * Zero diffs for 7 consecutive days → cutover readiness.
 *
 * Shadow mode has three operational states:
 *
 *   1. OFF      — Normal operation. Legacy and Laravel run independently.
 *                  No comparison is performed.
 *
 *   2. PASSIVE  — Laravel is the primary system. After each transfer
 *                  operation, the legacy system also processes the same
 *                  operation (if available). Results are compared and
 *                  logged to shadow_transfer_comparisons table. Diffs
 *                  trigger alerts but don't block operations.
 *
 *   3. ACTIVE   — Both systems process every operation simultaneously.
 *                  The legacy system's result is treated as the "gold"
 *                  reference. If Laravel's result differs, an alert is
 *                  raised and the diff is logged. Operations continue
 *                  but the comparison dashboard highlights mismatches.
 *
 * Transition plan:
 *   OFF → PASSIVE:  After Phase 1-6 code review sign-off
 *   PASSIVE → ACTIVE: After 3 days of zero diffs in passive mode
 *   ACTIVE → CUTOVER: After 7 consecutive days of zero diffs
 *   ACTIVE → OFF:    If critical diffs are found (rollback)
 */
return [

    /**
     * Master toggle. Set to true to enable shadow mode infrastructure.
     * When false, the shadow service, artisan commands, and dashboard
     * routes are still available but comparison runs are skipped.
     */
    'enabled' => env('SHADOW_MODE_ENABLED', false),

    /**
     * Operational state: 'off', 'passive', 'active'.
     *
     * 'off'      — No comparison. Normal single-system operation.
     * 'passive'  — Laravel primary, legacy comparison after each op.
     * 'active'   — Dual-write, comparison on every operation.
     */
    'mode' => env('SHADOW_MODE_MODE', 'off'),

    /**
     * Legacy MySQL connection for reading legacy transfer data.
     * Uses the same archive connection (read-only).
     * This is used to read legacy warehouse_transfers data for comparison.
     */
    'legacy_connection' => env('SHADOW_MODE_LEGACY_CONNECTION', 'archive'),

    /**
     * Cutover readiness thresholds.
     *
     * consecutive_days_zero_diff: Number of consecutive days with zero
     *   diffs required before cutover can proceed. Default: 7.
     *
     * max_tolerance_qty: Maximum acceptable qty difference between
     *   legacy and Laravel stock movements. Default: 0.0001.
     *
     * max_tolerance_rate: Maximum acceptable rate (avg_cost) difference.
     *   Default: 0.01 (1 cent).
     *
     * max_tolerance_amount: Maximum acceptable total_amount difference.
     *   Default: 0.01.
     */
    'cutover' => [
        'consecutive_days_zero_diff' => env('SHADOW_CUTOVER_DAYS', 7),
        'max_tolerance_qty'    => 0.0001,
        'max_tolerance_rate'   => 0.01,
        'max_tolerance_amount' => 0.01,
    ],

    /**
     * Comparison scope — which aspects to compare.
     *
     * stock_movements: Compare stock_transactions (qty, rate, warehouse)
     *   created by each system for the same transfer.
     *
     * gl_postings: Compare journal_entries and journal_lines created.
     *   For same-branch transfers, both should have NO GL (this is
     *   a correctness check, not a comparison of GL amounts).
     *
     * status: Compare the transfer status after each operation.
     *
     * avg_cost: Compare the resulting warehouse avg_cost at both
     *   source and destination warehouses after the transfer.
     *
     * reversal_order: Verify that reversal follows dest-IN-first
     *   ordering in both systems.
     */
    'comparison_scope' => [
        'stock_movements'  => true,
        'gl_postings'      => true,
        'status'           => true,
        'avg_cost'         => true,
        'reversal_order'   => true,
    ],

    /**
     * Alert configuration — when diffs are detected.
     *
     * log_channel: Laravel log channel for shadow mode alerts.
     * notify_email: Email address(es) to notify on diff detection.
     *   Leave empty to disable email alerts.
     * notify_on_critical: Whether to immediately escalate critical diffs
     *   (e.g., stock qty > tolerance or GL mismatch on same-branch).
     */
    'alerts' => [
        'log_channel'      => env('SHADOW_ALERT_LOG_CHANNEL', 'shadow'),
        'notify_email'     => env('SHADOW_ALERT_EMAIL', ''),
        'notify_on_critical' => true,
    ],

    /**
     * Comparison run schedule.
     *
     * auto_compare_after_ops: Automatically run comparison after each
     *   transfer create/confirm/cancel operation in passive/active mode.
     *
     * scheduled_compare: Run a batch comparison on a schedule (cron).
     *   Set to null to disable scheduled runs.
     *
     * batch_size: Number of transfers to compare in one scheduled batch.
     */
    'schedule' => [
        'auto_compare_after_ops' => true,
        'scheduled_compare'      => env('SHADOW_SCHEDULED_COMPARE', 'daily'), // 'daily', 'hourly', null
        'batch_size'             => 100,
    ],

    /**
     * Dashboard settings.
     *
     * show_legacy_data: Whether to show legacy system data in the
     *   shadow mode dashboard (requires legacy DB connection).
     *
     * diff_highlight_tolerance: Threshold for visually highlighting
     *   diffs in the dashboard UI. Values above this are shown in red.
     */
    'dashboard' => [
        'show_legacy_data'           => true,
        'diff_highlight_tolerance'   => 0.001,
    ],

    /**
     * Retention settings — how long to keep comparison records.
     *
     * comparison_retention_days: Days to keep shadow_transfer_comparisons
     *   records. Older records are purged by the shadow:purge command.
     *
     * zero_diff_retention_days: Days to keep zero-diff records
     *   (these are less interesting and can be purged sooner).
     */
    'retention' => [
        'comparison_retention_days'  => 90,
        'zero_diff_retention_days'   => 30,
    ],
];
