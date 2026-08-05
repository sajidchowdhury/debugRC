<?php

/**
 * Purchase Module Configuration — PURCHASING-API-1 (G-118).
 *
 * Single source of truth for the hardcoded constants that were previously
 * scattered across `App\Services\Purchase\PurchaseOrderService` (and a few
 * in `PurchaseReceiveService`). Every value is env-overridable so a
 * deployment can tune the knobs without a code change.
 *
 * Read via `config('purchase.<key>')` — NEVER via env() in service code
 * (env() in non-config code breaks `php artisan config:cache`).
 *
 * Surfaced constants (mirrors the list in AI_CONTEXT/purchasing/purchase-order.md §11 G10):
 *   - po_prefix          (default 'PO')  — PO code prefix
 *   - po_code_pad_length (default 4)     — zero-pad length for the daily sequence
 *   - over_receive_tolerance (default 0.0001) — float noise tolerance for the over-receive guard
 *   - below_tolerance_status_threshold (default 0.0001) — qty comparison tolerance for PO status flips
 *
 * The PO code itself is still minted by `DocumentSequenceService::nextCode`
 * under an advisory lock (atomicity preserved). This config only externalises
 * the prefix + pad + tolerance knobs.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | PO Code Prefix
    |--------------------------------------------------------------------------
    |
    | The alphabetic prefix for every purchase order code.
    | Final code shape: `<prefix>-YYYYMMDD-<padded-sequence>`
    |   e.g. PO-20260905-0042
    |
    | Legacy default: 'PO'. Override per-deployment via PURCHASE_PO_PREFIX.
    */
    'po_prefix' => env('PURCHASE_PO_PREFIX', 'PO'),

    /*
    |--------------------------------------------------------------------------
    | PO Code Pad Length
    |--------------------------------------------------------------------------
    |
    | Zero-pad length for the daily sequence component of the PO code.
    |   pad=4 → 0001..9999 (10k POs per day ceiling)
    |   pad=5 → 00001..99999 (100k POs per day ceiling)
    |
    | Legacy default: 4.
    */
    'po_code_pad_length' => (int) env('PURCHASE_PO_CODE_PAD_LENGTH', 4),

    /*
    |--------------------------------------------------------------------------
    | Document type key for DocumentSequenceService
    |--------------------------------------------------------------------------
    |
    | The `docType` argument passed to `DocumentSequenceService::nextCode()`.
    | Used as the advisory-lock namespace so that PO numbering never collides
    | with GRN, PRN, or other document types that share the same date.
    |
    | Default: 'purchase_order'.
    */
    'po_doc_type' => env('PURCHASE_PO_DOC_TYPE', 'purchase_order'),

    /*
    |--------------------------------------------------------------------------
    | Over-receive tolerance
    |--------------------------------------------------------------------------
    |
    | Float-noise tolerance for the over-receive guard in
    | `PurchaseOrderService::updateReceivedQty`. A receive is rejected only
    | when `$newReceived > $orderedQty + $tolerance`. The tolerance absorbs
    | representation noise on `numeric(14,4)` columns (e.g. 10.0000 vs
    | 10.00001 from a JS client).
    |
    | Set to 0 for strict comparison (NOT recommended — false positives on
    | float-rounded payloads).
    |
    | Default: 0.0001.
    */
    'over_receive_tolerance' => (float) env('PURCHASE_OVER_RECEIVE_TOLERANCE', 0.0001),

    /*
    |--------------------------------------------------------------------------
    | PO status-flip tolerance
    |--------------------------------------------------------------------------
    |
    | Float-noise tolerance for the "all items received" / "any items
    | received" check in `PurchaseOrderService::updateReceivedQty`. A PO
    | flips to `received` only when every line's `received_qty >= qty -
    | $tolerance`; it flips to `partial` only when at least one line's
    | `received_qty > $tolerance`.
    |
    | Default: 0.0001 (same value as over_receive_tolerance — kept as a
    | separate knob because the business rule could diverge: e.g. accept
    | 99.99% fulfillment as `received` while still rejecting 100.01% receives).
    */
    'below_tolerance_status_threshold' => (float) env('PURCHASE_BELOW_TOLERANCE_STATUS_THRESHOLD', 0.0001),

    /*
    |--------------------------------------------------------------------------
    | Approval threshold (PURCHASING-API-2 / G-116)
    |--------------------------------------------------------------------------
    |
    | POs with total_amount >= this threshold require manager approval
    | before they can be marked sent to the supplier. POs below the
    | threshold are auto-approved (no approval needed).
    |
    | This value is read ONCE by the seeding migration
    | (2026_09_05_000003_add_purchase_order_approval_columns) to populate
    | the `approval_workflows.min_amount` DB row. After the migration runs,
    | the DB row is the live source of truth — admins tune it at runtime
    | via /admin/approvals/workflows/{id}. The env value only affects the
    | INITIAL seed (re-running the migration does NOT update an existing
    | workflow row — it's idempotent via WHERE NOT EXISTS).
    |
    | Set to 0 to require approval for EVERY PO (matches ManualJournal's
    | min_amount=0 pattern). Default: 50000 BDT (~$450 USD).
    */
    'approval_threshold' => (float) env('PURCHASE_APPROVAL_THRESHOLD', 50000),

];
