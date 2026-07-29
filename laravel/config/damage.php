<?php

/**
 * Damage module configuration — Phase 3+.
 *
 * Centralizes the rules that govern the Damage module so they can be tuned
 * per-environment (e.g. a stricter production install) without code changes.
 * Phase 5 (Approval Workflow) will extend this with approval thresholds,
 * maker-checker roles, and notification routing.
 *
 * @see docs/DAMAGE_IMPLEMENTATION_PLAN.md
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Photo / Evidence requirement (Phase 3)
    |--------------------------------------------------------------------------
    | damage_types for which at least one photo attachment is REQUIRED before
    | the damage can be confirmed. Enforced in DamageService::confirmDamage.
    |
    | Rationale:
    |   - real_damage    : physical breakage — must be photographed for the
    |                      insurance claim / audit trail.
    |   - theft          : the scene / forced entry / missing item location
    |                      must be documented (and usually a police report PDF
    |                      is attached too).
    |   - quality_reject : the defect must be visible (otherwise "quality
    |                      reject" becomes a dumping ground for unaccounted
    |                      stock, recreating the original accountability gap).
    |
    | Excluded:
    |   - missing        : there is nothing physical to photograph (the stock
    |                      is GONE — that's the whole point). Phase 4 will
    |                      require an accountable employee instead.
    |   - customer_return: auto-created from a sales return — the return
    |                      itself is the evidence; re-requiring a photo would
    |                      block the automated linkage flow.
    |   - other          : catch-all, no hard rule (manual review).
    */
    'require_photo_for_types' => [
        'real_damage',
        'theft',
        'quality_reject',
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachment limits (Phase 3)
    |--------------------------------------------------------------------------
    */
    'attachment_max_per_damage' => env('DAMAGE_ATTACHMENT_MAX', 10),
    'attachment_max_size_kb'    => env('DAMAGE_ATTACHMENT_MAX_KB', 5120), // 5 MB
    'attachment_disk'           => env('DAMAGE_ATTACHMENT_DISK', 'local'), // private disk
    'attachment_folder'         => 'damage-evidence', // storage/app/private/damage-evidence/{damage_id}/

    /*
    |--------------------------------------------------------------------------
    | Witness & Accountable Employee (Phase 4)
    |--------------------------------------------------------------------------
    | damage_types for which a named responsible party is REQUIRED at create
    | time, closing the "employee declares it as damage because they couldn't
    | find it" loophole. Enforced in DamageService::createDamage.
    |
    |   - missing → accountable_employee_id required (someone owns the
    |               unaccounted-for stock; they're the recovery target).
    |   - theft   → witness_employee_id required (someone must corroborate
    |               a theft claim before it's written off — a single-person
    |               theft declaration is an abuse vector).
    |
    | Both lists are config-driven so a stricter install can add more types
    | (e.g. require an accountable employee for quality_reject too) without
    | code changes. customer_return is deliberately excluded — the
    | sales-return-linked auto-flow (SalesReturnService::createLinkedDamage-
    | WriteOffs) creates + confirms in one shot and has no human selecting
    | an employee, so requiring one would break the automation.
    |
    | recovery_transaction_type: the employee_ledger.transaction_type used
    | when posting a recovery (Dr employee_payable / Cr loss). 'deduction'
    | nets against the employee's payable / future salary. Must be one of
    | the employee_ledger CHECK values (advance|loan|repayment|salary|
    | deduction|adjustment).
    */
    'accountability' => [
        'require_accountable_for_types' => ['missing'],
        'require_witness_for_types'     => ['theft'],
        'recovery_transaction_type'     => 'deduction',
    ],
];
