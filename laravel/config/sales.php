<?php

/**
 * Sales Module Configuration — P1-2.
 *
 * Mirrors the legacy config/config.php sales constants:
 *   - SALES_STALE_DRAFT_DAYS (default 14)
 *   - SALES_STALE_DRAFT_AUTO_CANCEL (default false → Laravel: true)
 *
 * All values are env-overridable.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Stale Draft Threshold (days)
    |--------------------------------------------------------------------------
    | Draft invoices older than this many days (with no godown prep, no
    | challan, no reversal) are considered "stale" and eligible for
    | auto-cancellation by the sales:cancel-stale-drafts command.
    |
    | Legacy: SALES_STALE_DRAFT_DAYS=14
    */
    'stale_draft_days' => (int) env('SALES_STALE_DRAFT_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Auto-Cancel Enabled
    |--------------------------------------------------------------------------
    | When true, the scheduled `sales:cancel-stale-drafts` command runs
    | nightly at 02:00 and cancels stale drafts automatically.
    | When false, the scheduled run is skipped (manual invocations still work).
    |
    | Legacy: SALES_STALE_DRAFT_AUTO_CANCEL=false (Laravel defaults to true
    | because the scheduled command is the primary cleanup mechanism).
    */
    'stale_draft_auto_cancel' => (bool) env('SALES_STALE_DRAFT_AUTO_CANCEL', true),

    /*
    |--------------------------------------------------------------------------
    | System User ID for Auto-Cancellation
    |--------------------------------------------------------------------------
    | The user_id recorded in user_audit_log when the scheduled command
    | cancels stale drafts. Should be a system/admin account.
    | Default: 1 (typically the first admin user seeded).
    */
    'stale_draft_cancelled_by' => (int) env('SALES_STALE_DRAFT_CANCELLED_BY', 1),

    /*
    |--------------------------------------------------------------------------
    | Max Drafts Per Run
    |--------------------------------------------------------------------------
    | Maximum number of stale drafts to cancel in a single command run.
    | Prevents long-running transactions on systems with many stale drafts.
    | Legacy: 200.
    */
    'stale_draft_max_per_run' => (int) env('SALES_STALE_DRAFT_MAX_PER_RUN', 200),

];
