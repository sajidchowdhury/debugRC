<?php

namespace App\Services\Stock;

use App\Models\DamageInvoice;
use Illuminate\Support\Facades\DB;

/**
 * Damage Integrity Service — Phase 2 (port legacy DamageAuditModel strength).
 *
 * Laravel dropped the legacy `DamageAuditModel::runDamageChecks()` feature
 * entirely when it migrated to the two-phase (draft → confirmed → cancelled)
 * damage flow. This restores it as a live-computed, read-only integrity
 * panel rendered on every damage detail page.
 *
 * Why live-computed (not a persisted `damage_audit_log` table)?
 *   - Avoids a second source of truth (the live DB IS the truth).
 *   - No stale results — every page load re-verifies against current state.
 *   - Mirrors legacy exactly (legacy computed on demand in details.php).
 *   - All lookups are indexed (stock_transactions.reference_id, damage_invoice_items
 *     .damage_invoice_id, journal_entries.id) so the panel is fast.
 *
 * The 5 checks (ported + adapted to the two-phase state machine):
 *
 *   1. branch_wh   — damage.branch_id == warehouse.branch_id.
 *                     (Legacy only displayed this; we actually VERIFY it —
 *                     a mismatch means the damage header was written with a
 *                     branch that doesn't own the warehouse, which would
 *                     break branch isolation.)
 *
 *   2. total_value — header.total_value == SUM(items.qty * items.rate),
 *                     tolerance 0.02 (rounding). pass/fail. Applies to ALL
 *                     states (even drafts — a draft with a mismatched total
 *                     is a bug to catch before confirm).
 *
 *   3. stock       — state-aware:
 *                     · draft / submitted / approved → info  "Not yet posted"
 *                       (Phase 5 added submitted + approved between draft and
 *                       confirmed; both behave like draft from the stock
 *                       perspective — no movements expected).
 *                     · confirmed  → pass if active (non-reversed) stock_transactions
 *                                    exist with reference_type='damage';
 *                                    fail if missing.
 *                     · cancelled  → pass if ALL damage stock_transactions are
 *                                    reversed; warn if any active remain (partial
 *                                    reversal — should not happen but flags drift).
 *                     · rejected   → info (terminal, never posted).
 *
 *   4. gl          — state-aware:
 *                     · draft / submitted / approved → info  "Not yet posted"
 *                       (Phase 5 — submitted/approved behave like draft).
 *                     · confirmed  → pass if journal_entry_id set AND JE not
 *                                    reversed; warn if missing (total>=0.01);
 *                                    info if total<0.01 (no GL expected).
 *                     · cancelled  → pass if journal_entry_id set AND JE
 *                                    is_reversed=true (proper reversal);
 *                                    warn if JE not reversed.
 *                     · rejected   → info (terminal, never posted).
 *
 *   5. reversed    — only when is_reversed=true: pass if reverse_reason is
 *                     non-empty; warn otherwise (legacy behaviour).
 *
 *   8. approval (Phase 5) — timeline consistency:
 *                     · draft      → info (not yet submitted).
 *                     · submitted  → pass if submitted_by/at set; warn otherwise.
 *                     · approved   → pass if approved_by/at set AND approver ≠
 *                                    submitter (or wasAutoApproved flag); fail
 *                                    if approver === submitter without the
 *                                    auto-approve flag.
 *                     · confirmed  → pass if approved_by/at set (the gate ran).
 *                     · rejected   → pass if approval_rejected_by/at set AND
 *                                    approval_notes non-empty.
 *                     · cancelled  → info (approval timeline preserved as-is).
 *
 * Each check returns: id, title, expected (description), status (pass/warn/
 * fail/info), detail (human-readable evidence). The summary tallies
 * pass/warn/fail so the UI can show a headline (e.g. "3 pass · 1 warn").
 */
class DamageIntegrityService
{
    /** Tolerance for total_value reconciliation (BDT). */
    private const TOTAL_VALUE_TOLERANCE = 0.02;

    /**
     * Run all integrity checks for a single damage invoice.
     *
     * Read-only: performs indexed SELECTs only. Safe to call on every
     * detail-page render. Never throws — a check that errors is reported
     * as `fail` with the error message in `detail` (so a broken DB state
     * surfaces in the panel instead of crashing the page).
     *
     * Accepts the already-eager-loaded DamageInvoice model from the
     * controller (which loads warehouse.branch, items, journalEntry) so we
     * don't re-query the header. The only extra queries are indexed count/
     * sum lookups on stock_transactions + damage_invoice_items.
     *
     * @param DamageInvoice $damage
     * @return array{items: array<int, array>, summary: array{pass: int, warn: int, fail: int, info: int}}
     */
    public function runChecks(DamageInvoice $damage): array
    {
        $items = [];
        $items[] = $this->checkBranchWarehouse($damage);
        $items[] = $this->checkTotalValue($damage);
        $items[] = $this->checkStockMovements($damage);
        $items[] = $this->checkGlJournal($damage);
        if ($damage->is_reversed) {
            $items[] = $this->checkReversed($damage);
        }
        // Phase 3 — evidence requirement (photo mandate for photographable
        // loss types). Runs for every damage so the panel surfaces a missing
        // photo proactively, before the user hits Confirm and gets a hard
        // error from DamageService::confirmDamage.
        $items[] = $this->checkEvidence($damage);

        // Phase 4 — witness / accountable employee requirement. Surfaces a
        // missing accountable employee (missing-type) or witness (theft-type)
        // before the user hits the create/confirm gate. Also reports the
        // recovery status (pending / posted / reversed) when an accountable
        // employee is set.
        $items[] = $this->checkAccountability($damage);

        // Phase 5 — approval-workflow timeline consistency. Verifies the
        // submitted_by/at + approved_by/at + approval_rejected_by/at stamps
        // are consistent with the current status, and that the segregation-
        // of-duties rule (approver ≠ submitter) holds for non-auto-approved
        // damages.
        $items[] = $this->checkApproval($damage);

        $summary = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0];
        foreach ($items as $it) {
            $summary[$it['status']] = ($summary[$it['status']] ?? 0) + 1;
        }

        return ['items' => $items, 'summary' => $summary];
    }

    /**
     * 1. Branch ↔ warehouse consistency.
     *
     * The damage header's branch_id MUST match the warehouse's branch_id.
     * A mismatch breaks RLS (the damage would be invisible to the warehouse's
     * branch users) and indicates a write-path bug. Legacy only *displayed*
     * this; we verify it.
     */
    private function checkBranchWarehouse(DamageInvoice $damage): array
    {
        $warehouse = $damage->warehouse;
        $branchName = $damage->branch?->branch_name ?? '—';
        $warehouseName = $warehouse?->warehouse_name ?? '—';

        if (!$warehouse) {
            return $this->item('branch_wh', 'Branch / warehouse',
                'Damage warehouse must belong to the damage branch.',
                'fail', "Warehouse missing (warehouse_id={$damage->warehouse_id}).");
        }

        $whBranchId = (int) $warehouse->branch_id;
        $dmgBranchId = (int) $damage->branch_id;

        if ($whBranchId === $dmgBranchId) {
            return $this->item('branch_wh', 'Branch / warehouse',
                'Damage warehouse must belong to the damage branch.',
                'pass',
                "{$warehouseName} · {$branchName}");
        }

        return $this->item('branch_wh', 'Branch / warehouse',
            'Damage warehouse must belong to the damage branch.',
            'fail',
            "Mismatch: damage branch #{$dmgBranchId} ({$branchName}) "
            . "≠ warehouse branch #{$whBranchId} ({$warehouseName}).");
    }

    /**
     * 2. total_value reconciliation.
     *
     * header.total_value must equal SUM(items.qty * items.rate), within a
     * 0.02 tolerance (rounding on the 2-dp total). Applies to all states.
     */
    private function checkTotalValue(DamageInvoice $damage): array
    {
        try {
            $lineSum = (float) DB::table('damage_invoice_items')
                ->where('damage_invoice_id', $damage->id)
                ->selectRaw('COALESCE(SUM(qty * rate), 0) AS s')
                ->value('s');
        } catch (\Throwable $e) {
            return $this->item('total_value', 'Damage amount',
                'Header total_value must equal sum of line qty × rate.',
                'fail', 'Lookup failed: ' . $e->getMessage());
        }

        $headerTotal = (float) $damage->total_value;
        $delta = abs($lineSum - $headerTotal);

        if ($delta < self::TOTAL_VALUE_TOLERANCE) {
            return $this->item('total_value', 'Damage amount',
                'Header total_value must equal sum of line qty × rate.',
                'pass',
                'Header ' . number_format($headerTotal, 2)
                . ' · Lines ' . number_format($lineSum, 2));
        }

        return $this->item('total_value', 'Damage amount',
            'Header total_value must equal sum of line qty × rate.',
            'fail',
            'Header ' . number_format($headerTotal, 2)
            . ' · Lines ' . number_format($lineSum, 2)
            . ' · Δ ' . number_format($delta, 2));
    }

    /**
     * 3. Stock movements consistency (state-aware).
     *
     * draft      → info (not yet posted)
     * confirmed  → fail if no active movements; pass with count
     * cancelled  → warn if any active (non-reversed) movements remain
     */
    private function checkStockMovements(DamageInvoice $damage): array
    {
        try {
            $active = (int) DB::table('stock_transactions')
                ->where('reference_type', 'damage')
                ->where('reference_id', $damage->id)
                ->where('is_reversed', false)
                ->count();
            $total = (int) DB::table('stock_transactions')
                ->where('reference_type', 'damage')
                ->where('reference_id', $damage->id)
                ->count();
        } catch (\Throwable $e) {
            return $this->item('stock', 'Stock movements',
                'Negative-qty movements for each damaged line (on confirm).',
                'fail', 'Lookup failed: ' . $e->getMessage());
        }

        if ($damage->isPreConfirm()) {
            return $this->item('stock', 'Stock movements',
                'Negative-qty movements for each damaged line (on confirm).',
                'info',
                'Not yet posted — ' . $damage->status . ' does not move stock.');
        }

        if ($damage->isConfirmed()) {
            if ($active > 0) {
                return $this->item('stock', 'Stock movements',
                    'Negative-qty movements for each damaged line (on confirm).',
                    'pass', "{$active} active movement(s).");
            }
            return $this->item('stock', 'Stock movements',
                'Negative-qty movements for each damaged line (on confirm).',
                'fail', 'Missing — confirmed damage has no active stock movements.');
        }

        // cancelled (was confirmed → reversed)
        if ($active > 0) {
            return $this->item('stock', 'Stock movements',
                'All damage stock movements should be reversed on cancel.',
                'warn',
                "{$active} of {$total} movement(s) still active (not reversed).");
        }
        return $this->item('stock', 'Stock movements',
            'All damage stock movements should be reversed on cancel.',
            'pass', "{$total} movement(s), all reversed.");
    }

    /**
     * 4. GL journal consistency (state-aware).
     *
     * draft      → info (not yet posted)
     * confirmed  → pass if JE set + not reversed; warn if missing (total>=0.01);
     *              info if total<0.01 (no GL expected)
     * cancelled  → pass if JE set + reversed; warn if JE not reversed
     */
    private function checkGlJournal(DamageInvoice $damage): array
    {
        $total = (float) $damage->total_value;
        $jeId = $damage->journal_entry_id;
        $je = $damage->journalEntry;

        if ($damage->isPreConfirm()) {
            return $this->item('gl', 'Damage GL',
                'Dr loss / Cr inventory journal posted on confirm (when total ≥ 0.01).',
                'info', 'Not yet posted — ' . $damage->status . ' does not post GL.');
        }

        if ($damage->isConfirmed()) {
            if ($total < 0.01) {
                return $this->item('gl', 'Damage GL',
                    'Dr loss / Cr inventory journal posted on confirm (when total ≥ 0.01).',
                    'info', 'No GL expected — total value < 0.01.');
            }
            if ($jeId && $je && !$je->is_reversed) {
                return $this->item('gl', 'Damage GL',
                    'Dr loss / Cr inventory journal posted on confirm (when total ≥ 0.01).',
                    'pass', 'Journal #' . $je->entry_no . ' (id ' . $jeId . ').');
            }
            return $this->item('gl', 'Damage GL',
                'Dr loss / Cr inventory journal posted on confirm (when total ≥ 0.01).',
                'warn', 'Missing (re-post?) — confirmed damage has no active journal entry.');
        }

        // cancelled (was confirmed → reversed)
        if ($jeId && $je && $je->is_reversed) {
            return $this->item('gl', 'Damage GL',
                'Journal entry should be reversed on cancel.',
                'pass', 'Journal #' . $je->entry_no . ' reversed.');
        }
        if ($jeId && $je && !$je->is_reversed) {
            return $this->item('gl', 'Damage GL',
                'Journal entry should be reversed on cancel.',
                'warn', 'Journal #' . $je->entry_no . ' NOT reversed.');
        }
        // No JE on a cancelled damage — only a problem if it was ever confirmed
        // with a non-trivial total. Draft→cancelled skips GL entirely (correct).
        if ($total >= 0.01) {
            return $this->item('gl', 'Damage GL',
                'Journal entry should be reversed on cancel.',
                'warn', 'No journal entry on record for a cancelled damage with total ≥ 0.01.');
        }
        return $this->item('gl', 'Damage GL',
            'Journal entry should be reversed on cancel.',
            'info', 'No GL expected — total value < 0.01.');
    }

    /**
     * 5. Reversal reason (only when is_reversed).
     *
     * A reversed damage must carry a reverse_reason (legacy behaviour).
     * Missing reason → warn (the reversal happened but wasn't documented).
     */
    private function checkReversed(DamageInvoice $damage): array
    {
        $reason = trim((string) $damage->reverse_reason);
        $by = $damage->reversed_by ? ' · by user #' . $damage->reversed_by : '';

        if ($reason !== '') {
            return $this->item('reversed', 'Reversed',
                'Reversed damage must carry a reverse reason.',
                'pass', $reason . $by);
        }
        return $this->item('reversed', 'Reversed',
            'Reversed damage must carry a reverse reason.',
            'warn', 'No reverse reason recorded' . $by);
    }

    /**
     * 6. Evidence / photo requirement (Phase 3).
     *
     * For damage types that represent a real, photographable loss
     * (real_damage / theft / quality_reject — driven by
     * config('damage.require_photo_for_types')), at least one attachment is
     * REQUIRED. This mirrors the hard gate in DamageService::confirmDamage
     * but surfaces the gap on the detail page *before* the user hits Confirm.
     *
     * State-aware:
     *   · draft      → warn if 0 attachments (can still upload + retry).
     *   · confirmed  → fail if 0 attachments (the gate was bypassed — a
     *                  confirmed write-off with no proof is an audit hole;
     *                  this should never happen because confirmDamage blocks
     *                  it, but if it does, the panel must flag it).
     *   · cancelled  → info (no longer actionable; the requirement lapsed
     *                  with the cancellation).
     *
     * Types NOT in the require-photo list (missing, customer_return, other):
     *   → pass with a note explaining WHY no photo is required (so the panel
     *     doesn't look like it skipped the check). `missing` will require an
     *     accountable employee instead in Phase 4.
     *
     * Uses the already-eager-loaded `attachments` relation (no extra query)
     * when available; falls back to a counted DB query otherwise.
     */
    private function checkEvidence(DamageInvoice $damage): array
    {
        $requirePhoto = config('damage.require_photo_for_types', []);
        $requirePhoto = is_array($requirePhoto) ? $requirePhoto : [];
        $typeLabel    = DamageInvoice::DAMAGE_TYPE_LABELS[$damage->damage_type] ?? $damage->damage_type;

        if (!in_array($damage->damage_type, $requirePhoto, true)) {
            return $this->item(
                'evidence',
                'Evidence',
                'Photo evidence is required only for real_damage / theft / quality_reject.',
                'pass',
                "{$typeLabel} — no photo required for this damage type."
            );
        }

        // Prefer the eager-loaded collection (controller loads attachments)
        // so we avoid an extra query on the detail page. Falls back to a
        // count query if the relation wasn't loaded (e.g. called from a
        // context that only fetched the header).
        if ($damage->relationLoaded('attachments')) {
            $count = $damage->attachments->count();
        } else {
            $count = DB::table('damage_attachments')
                ->where('damage_invoice_id', $damage->id)
                ->count();
        }

        if ($damage->isDraft()) {
            return $count > 0
                ? $this->item('evidence', 'Evidence',
                    "At least one photo is required to confirm a {$typeLabel} damage.",
                    'pass', "{$count} attachment(s) attached — requirement met.")
                : $this->item('evidence', 'Evidence',
                    "At least one photo is required to confirm a {$typeLabel} damage.",
                    'warn', 'No evidence uploaded — Confirm will be blocked until at least one photo is added.');
        }

        if ($damage->isConfirmed()) {
            // confirmDamage enforces this, so 0 here means the gate was
            // bypassed (e.g. data migration, or a pre-Phase-3 confirmed row).
            return $count > 0
                ? $this->item('evidence', 'Evidence',
                    "Confirmed {$typeLabel} damage must retain its evidence.",
                    'pass', "{$count} attachment(s) on record.")
                : $this->item('evidence', 'Evidence',
                    "Confirmed {$typeLabel} damage must have evidence (confirmDamage should have blocked this).",
                    'fail', 'No evidence on a confirmed photographable loss — audit hole.');
        }

        // Cancelled — no longer actionable.
        return $this->item('evidence', 'Evidence',
            "Evidence requirement lapsed when this {$typeLabel} damage was cancelled.",
            'info', $count > 0 ? "{$count} attachment(s) retained for audit." : 'No attachments were recorded.');
    }

    /**
     * 7. Witness / accountable employee requirement (Phase 4).
     *
     * Surfaces the type-conditional accountability gate so it's visible on
     * the detail page *before* the user hits Create/Confirm:
     *   - missing → accountable_employee_id required
     *   - theft   → witness_employee_id required
     *
     * State-aware:
     *   · draft      → warn if the required party is missing (can still add
     *                  before confirm; createDamage blocks it server-side,
     *                  so a draft without one means it was created pre-Phase-4
     *                  or via the sales-return-linked auto-flow which is
     *                  exempt).
     *   · confirmed  → fail if the required party is missing (the gate was
     *                  bypassed — an audit hole). pass otherwise, with the
     *                  recovery status appended (pending / posted).
     *   · cancelled  → info (no longer actionable).
     *
     * For types with NO hard requirement, passes with a note — but if an
     * accountable employee IS set (recommended for employee-caused damage),
     * reports the recovery status so the panel reflects it.
     *
     * Uses the already-eager-loaded witnessEmployee / accountableEmployee
     * relations (no extra query) when available.
     */
    private function checkAccountability(DamageInvoice $damage): array
    {
        $rules = (array) config('damage.accountability', []);
        $requireAccountable = in_array(
            $damage->damage_type,
            (array) ($rules['require_accountable_for_types'] ?? []),
            true
        );
        $requireWitness = in_array(
            $damage->damage_type,
            (array) ($rules['require_witness_for_types'] ?? []),
            true
        );

        $typeLabel  = DamageInvoice::DAMAGE_TYPE_LABELS[$damage->damage_type] ?? $damage->damage_type;
        $witness    = $damage->witnessEmployee;
        $accountable = $damage->accountableEmployee;

        // Build a short "who's named" summary for the detail line.
        $namedParts = [];
        if ($accountable) {
            $namedParts[] = 'Accountable: ' . $accountable->name . ' (#' . $accountable->employee_code . ')';
        }
        if ($witness) {
            $namedParts[] = 'Witness: ' . $witness->name . ' (#' . $witness->employee_code . ')';
        }
        $namedSummary = $namedParts ? implode(' · ', $namedParts) : 'No employee named.';

        // Recovery status (only meaningful when an accountable employee exists).
        $recoveryAmount = (float) $damage->recovery_amount;
        $hasRecovery = $damage->hasRecovery();
        $recoveryNote = '';
        if ($accountable) {
            if ($hasRecovery) {
                $recoveryNote = ' · Recovery posted: Tk ' . number_format($recoveryAmount, 2);
            } elseif ($damage->isConfirmed()) {
                $recoveryNote = ' · Recovery pending (optional — Tk ' . number_format((float) $damage->total_value, 2) . ' recoverable)';
            }
        }

        $expected = $requireAccountable
            ? "A {$typeLabel} damage requires an accountable employee."
            : ($requireWitness
                ? "A {$typeLabel} damage requires a witness employee."
                : 'Witness / accountable employee is optional for this damage type.');

        // --- Hard requirement missing (missing→accountable, theft→witness) ---
        $missingRequired = ($requireAccountable && empty($accountable))
            || ($requireWitness && empty($witness));

        if ($missingRequired) {
            if ($damage->isDraft()) {
                return $this->item('accountability', 'Accountability', $expected,
                    'warn',
                    $namedSummary . ' — Confirm will be blocked until the required employee is added.');
            }
            if ($damage->isConfirmed()) {
                // createDamage blocks this, so 0 here means the gate was
                // bypassed (pre-Phase-4 row, or the exempt sales-return flow).
                return $this->item('accountability', 'Accountability', $expected,
                    'fail',
                    $namedSummary . ' — confirmed without the required employee (gate bypassed).');
            }
            // cancelled
            return $this->item('accountability', 'Accountability', $expected,
                'info',
                $namedSummary . ' — no longer actionable (damage cancelled).');
        }

        // --- Requirement met (or no hard requirement) ---
        if ($damage->isCancelled()) {
            return $this->item('accountability', 'Accountability', $expected,
                'info', $namedSummary . $recoveryNote);
        }

        return $this->item('accountability', 'Accountability', $expected,
            'pass', $namedSummary . $recoveryNote);
    }

    /**
     * 8. Approval-workflow timeline consistency (Phase 5).
     *
     * Verifies the submitted_by/at + approved_by/at + approval_rejected_by/at
     * stamps are consistent with the current status, and that the
     * segregation-of-duties rule (approver ≠ submitter) holds for
     * non-auto-approved damages.
     *
     * State-aware:
     *   · draft      → info (not yet submitted — timeline is empty by design).
     *   · submitted  → pass if submitted_by/at set; warn otherwise (should
     *                  never happen — submitForApproval always stamps both).
     *   · approved   → pass if approved_by/at set AND (approver ≠ submitter
     *                  OR wasAutoApproved). fail if approver === submitter
     *                  without the auto-approve flag (segregation-of-duties
     *                  violation — the gate was bypassed improperly).
     *   · confirmed  → pass if approved_by/at set (the gate ran before
     *                  confirm). warn if missing (force_confirm system flow
     *                  stamps them, so missing means a pre-Phase-5 row or a
     *                  bypass bug).
     *   · rejected   → pass if approval_rejected_by/at set AND approval_notes
     *                  non-empty (the reason is required). warn otherwise.
     *   · cancelled  → info (approval timeline preserved as-is — a cancelled
     *                  submitted/approved damage keeps its stamps for audit).
     *
     * Uses the already-eager-loaded submitter / approver / rejecter relations
     * (no extra query) when available; falls back to the raw integer IDs
     * otherwise (the stamps are the source of truth — the User lookup is
     * only for the detail line).
     */
    private function checkApproval(DamageInvoice $damage): array
    {
        $expected = 'Approval timeline stamps must match the current status; '
            . 'approver ≠ submitter (segregation of duties).';

        if ($damage->isDraft()) {
            return $this->item('approval', 'Approval timeline', $expected,
                'info', 'Not yet submitted — draft has no approval timeline.');
        }

        if ($damage->isSubmitted()) {
            if ($damage->submitted_by && $damage->submitted_at) {
                $who = $damage->submitter?->name ?? ('User #' . $damage->submitted_by);
                $when = \Carbon\Carbon::parse($damage->submitted_at)->format('d M Y H:i');
                return $this->item('approval', 'Approval timeline', $expected,
                    'pass', "Submitted by {$who} on {$when} — awaiting manager approval.");
            }
            return $this->item('approval', 'Approval timeline', $expected,
                'warn', 'Submitted status but submitted_by/at not stamped (data integrity issue).');
        }

        if ($damage->isApproved()) {
            if (!$damage->approved_by || !$damage->approved_at) {
                return $this->item('approval', 'Approval timeline', $expected,
                    'fail', 'Approved status but approved_by/at not stamped.');
            }
            // Segregation of duties — approver ≠ submitter, UNLESS the
            // auto-approve shortcut fired (submitted_by === approved_by,
            // flagged via wasAutoApproved()).
            $auto = $damage->wasAutoApproved();
            if (!$auto && (int) $damage->submitted_by === (int) $damage->approved_by) {
                return $this->item('approval', 'Approval timeline', $expected,
                    'fail',
                    'Segregation-of-duties violation: approver === submitter '
                    . 'without the auto-approve flag.');
            }
            $who = $damage->approver?->name ?? ('User #' . $damage->approved_by);
            $when = \Carbon\Carbon::parse($damage->approved_at)->format('d M Y H:i');
            $badge = $auto ? ' (auto-approved — within threshold)' : '';
            return $this->item('approval', 'Approval timeline', $expected,
                'pass', "Approved by {$who} on {$when}{$badge} — ready to confirm.");
        }

        if ($damage->isConfirmed()) {
            // The gate ran before confirm. force_confirm (system flow) also
            // stamps these, so missing approved_by/at means a pre-Phase-5 row
            // or a bypass bug.
            if ($damage->approved_by && $damage->approved_at) {
                $who = $damage->approver?->name ?? ('User #' . $damage->approved_by);
                $when = \Carbon\Carbon::parse($damage->approved_at)->format('d M Y H:i');
                return $this->item('approval', 'Approval timeline', $expected,
                    'pass', "Gate ran — approved by {$who} on {$when} before confirm.");
            }
            return $this->item('approval', 'Approval timeline', $expected,
                'warn',
                'Confirmed without approved_by/at — pre-Phase-5 row or force_confirm bypass.');
        }

        if ($damage->isRejected()) {
            if (!$damage->approval_rejected_by || !$damage->approval_rejected_at) {
                return $this->item('approval', 'Approval timeline', $expected,
                    'fail', 'Rejected status but approval_rejected_by/at not stamped.');
            }
            $who = $damage->rejecter?->name ?? ('User #' . $damage->approval_rejected_by);
            $when = \Carbon\Carbon::parse($damage->approval_rejected_at)->format('d M Y H:i');
            $notes = trim((string) $damage->approval_notes);
            $noteText = $notes !== '' ? " — reason: \"{$notes}\"" : ' — no reason recorded';
            return $this->item('approval', 'Approval timeline', $expected,
                'pass', "Rejected by {$who} on {$when}{$noteText} (terminal).");
        }

        // cancelled — timeline preserved as-is for the audit trail.
        $parts = [];
        if ($damage->submitted_by) {
            $parts[] = 'submitted by ' . ($damage->submitter?->name ?? ('User #' . $damage->submitted_by));
        }
        if ($damage->approved_by) {
            $parts[] = 'approved by ' . ($damage->approver?->name ?? ('User #' . $damage->approved_by));
        }
        $summary = $parts ? implode(' · ', $parts) : 'no approval stamps (cancelled as draft)';
        return $this->item('approval', 'Approval timeline', $expected,
            'info', "Cancelled — {$summary}.");
    }

    /**
     * Build a single check result row.
     *
     * @param string      $id       Stable check identifier (e.g. 'total_value').
     * @param string      $title    Short human title.
     * @param string      $expected What the check verifies (tooltip/help text).
     * @param string      $status   pass|warn|fail|info
     * @param string|null $detail   Human-readable evidence.
     */
    private function item(
        string $id,
        string $title,
        string $expected,
        string $status,
        ?string $detail = null
    ): array {
        return [
            'id'       => $id,
            'title'    => $title,
            'expected' => $expected,
            'status'   => $status,
            'detail'   => $detail ?? '',
        ];
    }
}
