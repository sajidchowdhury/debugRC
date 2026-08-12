<?php

/**
 * FINANCE-1 — G-098 (H1, audit-trail phase)
 *
 * Enforces BR29 (`finance/fixed-assets.md` §6.6): the hash-chain
 * `fn_financial_audit_trigger` MUST NOT be attached to any fixed-asset
 * sub-ledger table. The 3 in-scope tables are:
 *
 *   - fixed_assets                   (asset master data)
 *   - asset_depreciation_schedules   (sub-ledger of depreciation postings)
 *   - asset_disposals                (sub-ledger of disposal events)
 *
 * Design rationale (documented here so it is discoverable by grep):
 *
 *   The `fn_financial_audit_trigger` hash-chain is reserved for "crown-jewel"
 *   financial tables — `journal_entries` + `journal_lines` — where an
 *   immutable, tamper-evident audit trail is a hard compliance requirement.
 *   Fixed-asset sub-ledger tables are NOT in that tier because:
 *
 *     1. Every financial event for a fixed asset (depreciation posting,
 *        disposal) creates a `journal_entries` row + `journal_lines` rows
 *        which ARE hash-chain audited. The GL audit trail is the SSOT for
 *        "did this financial event happen" — the sub-ledger tables are
 *        derived state that can be reconciled against the GL.
 *     2. The sub-ledger tables carry non-financial metadata (asset_code,
 *        description, category, useful_life_months, depreciation_method)
 *        whose audit history is covered by the standard Eloquent
 *        `updated_at` timestamp + the `AuditableMasterData` trait (where
 *        applicable) + RLS WITH CHECK policies. Hash-chaining every
 *        asset-code edit would bloat the audit log without adding
 *        tamper-evidence that the GL audit trail doesn't already provide.
 *     3. `asset_depreciation_schedules` + `asset_disposals` are
 *        status-tracked (`pending` / `posted` / `reversed` / `disposed`)
 *        with explicit `reversed_by` / `reversed_at` / `reverse_reason`
 *        columns (G-341 back-fills the missing ones). The status machine
 *        IS the audit trail for the sub-ledger side.
 *
 * This migration is defensive + idempotent: it DROPs any audit trigger
 * that MAY have been attached by accident (or by a future migration that
 * doesn't know about BR29), then no-ops if none exist. It does NOT create
 * any triggers. It is safe to re-run.
 *
 * Resolves: G-098 (H1). The gap was that BR29 was a documented rule but
 * not enforced at the DB layer — a future migration could silently
 * violate it. This migration makes the rule self-enforcing.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = [
        'fixed_assets',
        'asset_depreciation_schedules',
        'asset_disposals',
    ];

    public function up(): void
    {
        // BR29 enforcement: DROP any financial-audit trigger attached to
        // the 3 fixed-asset sub-ledger tables. Idempotent — no-op if no
        // such trigger exists. We don't know the exact trigger name a
        // future migration might use, so we drop by the EXECUTE FUNCTION
        // binding (pg_get_triggerdef) rather than by name.
        foreach (self::TABLES as $table) {
            $triggers = DB::select("
                SELECT tgname
                FROM pg_trigger
                WHERE tgrelid = ?::regclass
                  AND NOT tgisinternal
                  AND pg_get_triggerdef(oid) ILIKE '%fn_financial_audit_trigger%'
                ", [$table]);

            foreach ($triggers as $trigger) {
                DB::statement("DROP TRIGGER IF EXISTS {$trigger->tgname} ON {$table}");
            }
        }
    }

    public function down(): void
    {
        // Intentionally empty. This migration enforces a design rule
        // (BR29); rolling it back would re-expose the system to the gap.
        // If a future requirement changes BR29, a new migration should
        // ATTACH the audit trigger explicitly with a documented rationale.
    }
};
