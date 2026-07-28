<?php

/**
 * Stock Adjustment — approval-workflow configuration (Phase 3).
 *
 * Single source of truth for the maker-checker knobs. Read by
 * App\Services\Stock\StockAdjustmentPolicyService. Every value is
 * overridable via env() so deployments can tune the gate without editing
 * code (e.g. turn the gate off for a small single-branch tenant, or raise
 * the auto-approve threshold during data migration).
 *
 * Publish to the host with: php artisan vendor:publish --tag=stock-adjustment-config
 * (register the tag in AppServiceProvider if a UI-driven settings screen is
 * added later — Phase 4+ may add a DB-backed override like StockTake's
 * stock_take_policies table; for now config is sufficient because Stock
 * Adjustment is an infrequent, accountant-driven tool.)
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Require approval (maker-checker gate)
    |--------------------------------------------------------------------------
    |
    | When TRUE, every adjustment whose total_amount is NOT below the
    | auto_approve_below_value threshold MUST be submitted (draft→submitted)
    | and approved (submitted→approved) before it can be confirmed
    | (approved→confirmed, which posts stock + GL).
    |
    | When FALSE, adjustments can be confirmed directly from draft — UNLESS
    | the total_amount is ≥ max_value_without_secondary_approval, in which
    | case the force-approve threshold still routes them through approval.
    |
    | Default: true (segregation of duties for a financial-correction tool).
    */

    'require_approval' => env('STOCK_ADJ_REQUIRE_APPROVAL', true),

    /*
    |--------------------------------------------------------------------------
    | Auto-approve below value
    |--------------------------------------------------------------------------
    |
    | Only meaningful when require_approval = true. Adjustments whose
    | total_amount is STRICTLY below this threshold skip the human approval
    | gate — they can be confirmed directly from draft (one-step confirm),
    | OR if submitted, are auto-advanced to 'approved' inline by the service.
    |
    | Set to 0 to disable auto-approval (every adjustment goes through the
    | human gate when require_approval is on).
    |
    | Default: 1000 currency units (small corrections flow frictionlessly).
    */

    'auto_approve_below_value' => env('STOCK_ADJ_AUTO_APPROVE_VALUE', 1000),

    /*
    |--------------------------------------------------------------------------
    | Max value without secondary approval (force-approve threshold)
    |--------------------------------------------------------------------------
    |
    | When require_approval = FALSE, adjustments whose total_amount is ≥ this
    | threshold are STILL forced through the human approval gate. This lets a
    | tenant run small corrections without friction while still gating large,
    | high-impact adjustments.
    |
    | Set to 0 to disable the force-approve threshold (no adjustment is
    | forced through approval when require_approval is off).
    |
    | Default: 50000 currency units.
    */

    'max_value_without_secondary_approval' => env('STOCK_ADJ_FORCE_APPROVE_VALUE', 50000),

    /*
    |--------------------------------------------------------------------------
    | Approver roles
    |--------------------------------------------------------------------------
    |
    | Roles permitted to approve a submitted adjustment. The submitter
    | (submitted_by) is forbidden from approving their own submission
    | (segregation of duties — enforced in StockAdjustmentService::approveAdjustment).
    |
    | Default: admin, manager. (Accountant drafts + submits; admin/manager
    | approves — mirrors the legacy role matrix.)
    */

    'approver_roles' => ['admin', 'manager'],

    /*
    |--------------------------------------------------------------------------
    | Submitter roles
    |--------------------------------------------------------------------------
    |
    | Roles permitted to submit a draft adjustment for approval. The
    | drafter (created_by) is the natural submitter but anyone with a
    | submitter role may submit.
    |
    | Default: admin, accountant. (Matches the Phase 1 write-access gate.)
    */

    'submitter_roles' => ['admin', 'accountant'],

    /*
    |--------------------------------------------------------------------------
    | Confirmer roles
    |--------------------------------------------------------------------------
    |
    | Roles permitted to confirm (post stock + GL) an approved adjustment.
    | Typically the same as submitter_roles — the accountant who drafted
    | comes back to post after approval. Kept separate so a tenant could
    | restrict posting to admin only if desired.
    |
    | Default: admin, accountant.
    */

    'confirmer_roles' => ['admin', 'accountant'],

    /*
    |--------------------------------------------------------------------------
    | Force-confirmer roles (Phase 6.1)
    |--------------------------------------------------------------------------
    |
    | Roles permitted to FORCE-confirm a DECREASE adjustment past the
    | pipeline-availability check (physical − open sales-invoice dispatches).
    | The force path is the legitimate escape hatch for legacy-cleanup /
    | data-migration corrections that must post a decrease below the
    | pipeline-reserved qty. Every force-confirm is logged as a distinct
    | 'force_confirm' audit action (Phase 4 vocab) + requires a mandatory
    | force_reason, so the bypass is always visible in the audit timeline.
    |
    | Default: admin only. A tenant may add 'manager' if their governance
    | model allows manager-level overrides (the force_reason is still
    | mandatory + the action is still audited).
    */

    'force_confirmer_roles' => ['admin'],

    /*
    |--------------------------------------------------------------------------
    | Block closed period
    |--------------------------------------------------------------------------
    |
    | When TRUE, the adjustment_date on a new draft (or a submit) cannot fall
    | inside a closed accounting period for the adjustment's branch. The
    | check delegates to AccountingPeriodService::earliestOpenDate(). This
    | prevents back-dating a correction into an already-closed book.
    |
    | Default: true.
    */

    'block_closed_period' => env('STOCK_ADJ_BLOCK_CLOSED_PERIOD', true),

    /*
    |--------------------------------------------------------------------------
    | Stale-draft threshold (days)
    |--------------------------------------------------------------------------
    |
    | A draft older than this many days is flagged "stale" by the audit
    | health-check (Phase 8 will add a cleanup automation; for now this only
    | powers the audit screen's warn count). 0 disables the staleness check.
    |
    | Default: 7 days.
    */

    'stale_draft_days' => env('STOCK_ADJ_STALE_DRAFT_DAYS', 7),
];
