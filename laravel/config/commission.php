<?php

/**
 * Commission Module Configuration — SALES-AUDIT-1 (G-158).
 *
 * Single source of truth for the commission-module knobs that were either
 * hardcoded in `App\Services\Sales\CommissionService` or simply absent (no
 * default, no env override). Every value is env-overridable so a deployment
 * can tune the knobs without a code change.
 *
 * Read via `config('commission.<key>')` — NEVER via env() in service code
 * (env() in non-config code breaks `php artisan config:cache`).
 *
 * Surfaced knobs (mirrors the list in AI_CONTEXT/sales/commission.md §11 G7):
 *   - batch_minimum_amount          (default 0.01)
 *       Net-commission threshold below which `confirmPeriod` skips GL
 *       posting for a salesman and just marks the entries as confirmed.
 *       Replaces the hardcoded `0.01` literal at CommissionService.php L663.
 *   - max_rules_per_salesman        (default 0 = unlimited)
 *       Cap on the number of ACTIVE rules a single salesman may have.
 *       0 = no enforcement (legacy behaviour). Enforced in
 *       CommissionService::createRule.
 *   - default_rule_type             (default 'flat')
 *       Fallback rule_type when a caller omits it. The 4 valid types are
 *       flat / tiered / product_group / target_bonus.
 *   - default_target_period         (default 'monthly')
 *       Default `targets[*].period` for target_bonus rules when the caller
 *       omits the period field. Valid: monthly / quarterly / yearly.
 *   - auto_confirm_calculated_entries (default false)
 *       When true, `calculateForInvoice` auto-confirms each commission
 *       entry on creation (skips the month-end `confirmPeriod` batch).
 *       Default false — entries stay 'calculated' until the accountant
 *       runs the period batch (matches the existing business flow).
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Batch Minimum Amount (GL skip threshold)
    |--------------------------------------------------------------------------
    |
    | Net-commission threshold (in BDT) below which `CommissionService::confirmPeriod`
    | skips GL posting for a salesman and just marks the entries as confirmed.
    | Absorbs float noise so a salesman with net-zero commission (e.g. an
    | invoice commission exactly offset by a return reversal) doesn't post a
    | zero-amount journal entry.
    |
    | Set to 0 to ALWAYS post GL (NOT recommended — produces zero-amount JEs).
    |
    | Default: 0.01.
    */
    'batch_minimum_amount' => (float) env('COMMISSION_BATCH_MINIMUM_AMOUNT', 0.01),

    /*
    |--------------------------------------------------------------------------
    | Max Rules per Salesman
    |--------------------------------------------------------------------------
    |
    | Maximum number of ACTIVE (is_active=true, effective_to IS NULL OR
    | effective_to >= today) commission rules a single salesman may have.
    | 0 = no enforcement (legacy behaviour — allows unlimited overlapping
    | rules, which the GiST EXCLUDE constraint partially guards against but
    | does not fully prevent for non-overlapping date ranges).
    |
    | Enforced in `CommissionService::createRule` — throws RuntimeException
    | when the cap is exceeded.
    |
    | Default: 0 (unlimited — backward compatible).
    */
    'max_rules_per_salesman' => (int) env('COMMISSION_MAX_RULES_PER_SALESMAN', 0),

    /*
    |--------------------------------------------------------------------------
    | Default Rule Type
    |--------------------------------------------------------------------------
    |
    | Fallback `rule_type` used by `CommissionService::createRule` when the
    | caller omits the field. The 4 valid types are:
    |   - flat           — single percentage rate on every commission base
    |   - tiered          — graduated rates by cumulative sales threshold
    |   - product_group   — per-product-group rate
    |   - target_bonus    — bonus rate triggered when a target amount is hit
    |
    | The StoreCommissionRuleRequest FormRequest still REQUIRES rule_type
    | (so this default only applies to direct service-layer callers that
    | bypass the FormRequest — e.g. a seeder or a maintenance script).
    |
    | Default: 'flat'.
    */
    'default_rule_type' => env('COMMISSION_DEFAULT_RULE_TYPE', 'flat'),

    /*
    |--------------------------------------------------------------------------
    | Default Target Period
    |--------------------------------------------------------------------------
    |
    | Default `period` for `targets[*]` entries on a target_bonus rule when
    | the caller omits the period field. Valid values:
    |   - monthly   — target resets each calendar month
    |   - quarterly — target resets each quarter (Q1=Jan-Mar, etc.)
    |   - yearly    — target resets each calendar year
    |
    | Default: 'monthly'.
    */
    'default_target_period' => env('COMMISSION_DEFAULT_TARGET_PERIOD', 'monthly'),

    /*
    |--------------------------------------------------------------------------
    | Auto-Confirm Calculated Entries
    |--------------------------------------------------------------------------
    |
    | When true, `CommissionService::calculateForInvoice` auto-confirms each
    | commission entry on creation (sets status='confirmed' immediately,
    | skipping the month-end `confirmPeriod` batch).
    |
    | Default false — entries stay 'calculated' until the accountant runs
    | the period batch (matches the existing business flow documented in
    | commission.md §6). Set to true for deployments that want real-time
    | commission confirmation (e.g. small teams without a dedicated
    | accountant running month-end batches).
    |
    | NOTE: when true, the GL posting (Dr Commission Expense / Cr Commission
    | Payable) happens inline on every invoice-payment allocation. This
    | multiplies the journal-entry write path by N (one per allocation vs
    | one per salesman per month) — only enable when the transaction volume
    | is low enough to absorb the extra writes.
    |
    | Default: false.
    */
    'auto_confirm_calculated_entries' => (bool) env('COMMISSION_AUTO_CONFIRM_CALCULATED_ENTRIES', false),

];
