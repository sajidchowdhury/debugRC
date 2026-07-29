<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Damage Phase 5 — Approval Workflow (Maker-Checker + Threshold Escalation).
 *
 * Inserts a configurable approval gate between draft and confirm:
 *
 *   draft ──submit──► submitted ──approve──► approved ──confirm──► confirmed
 *    │                   │                     │                     │
 *    │ cancel            │ reject              │ cancel              │ cancel
 *    ▼                   ▼                     ▼                     ▼
 *  cancelled         rejected              cancelled             cancelled
 *
 * No single person can both create AND post a material write-off. A
 * warehouse_manager (who can create drafts) can submit for approval but
 * NEVER approve or confirm. Even admin/manager submitters are subject to
 * the maker-checker gate when the damage exceeds the configured threshold
 * — below the threshold, an admin/manager submitter is auto-approved inline
 * (so small damages aren't bottlenecked). warehouse_manager submitters are
 * ALWAYS routed to explicit approval regardless of amount.
 *
 * Segregation of duties is enforced at the service layer:
 *   - The user who submits (submitted_by) CANNOT approve their own submission.
 *   - DamageService::approve() throws if approved_by === submitted_by.
 *
 * The approval gate is configurable via config/damage.php (Phase 5 adds the
 * `approval` block). Unlike stock_take (which introduced a dedicated
 * stock_take_policies table), Damage keeps the threshold in config/env —
 * the threshold is a single number that rarely changes, and a DB table
 * would be overkill. The config is cached by Laravel's config cache.
 *
 * Schema changes:
 *   1. Drop & re-add the status CHECK on damage_invoices to allow
 *      'submitted', 'approved', 'rejected' (the three new states).
 *   2. ADD COLUMN submitted_by, submitted_at, approved_by, approved_at,
 *      approval_rejected_by, approval_rejected_at, approval_notes on
 *      damage_invoices.
 *   3. Partial index on submitted damages — powers the "awaiting my
 *      approval" worklist query for managers.
 *
 * System-originated damages (sales-return-linked auto-flow) bypass the gate
 * via a `force_confirm` flag in DamageService::confirmDamage — they stamp
 * submitted_by/at + approved_by/at with the system user + an audit note
 * "Auto-approved: linked to sales return #{code}". This preserves the
 * one-shot create+confirm automation in SalesReturnService without
 * weakening the maker-checker rule for human-created damages.
 *
 * References:
 *   - docs/DAMAGE_IMPLEMENTATION_PLAN.md  §Phase 5 (lines 1054-1119)
 *   - app/Services/Stock/DamageService.php  submitForApproval/approve/reject
 *   - app/Policies/DamagePolicy.php  submit/approve/reject
 *   - database/migrations/2025_07_28_000001_add_approval_workflow_to_stock_take_sessions.php
 *     (parallel pattern — stock_take's maker-checker gate, mirrored here)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Expand the status CHECK constraint ───────────────────────
        // The original inline CHECK (from 03_stock.sql + the Phase-1
        // migration 2026_01_01_000001) was auto-named
        // `damage_invoices_status_check` by PostgreSQL and allows only
        // ('draft','confirmed','cancelled'). Drop & re-add with the expanded
        // allow-list so the three new states are valid.
        $this->dropStatusCheckConstraint();
        DB::statement(
            "ALTER TABLE damage_invoices "
            . "ADD CONSTRAINT damage_invoices_status_check "
            . "CHECK (status IN ('draft','submitted','approved','confirmed','cancelled','rejected'))"
        );

        // ── 2. Approval-workflow columns on damage_invoices ─────────────
        // Plain `integer` for the *_by columns (no FK) — mirrors the existing
        // reversed_by / created_by pattern on this table and the stock_take
        // sessions approval columns. Avoids FK-deferral complications during
        // user deletion. The application layer resolves the integer to a
        // User model via the show-page eager-load.
        //
        // submitted_by / submitted_at — who pushed the draft into the
        //   approval queue and when. Set once by submitForApproval; never
        //   overwritten (re-submission after rejection creates a NEW row).
        // approved_by / approved_at — who approved and when. Set by approve()
        //   OR by submitForApproval's auto-approve shortcut (when the
        //   submitter is admin/manager AND total ≤ threshold).
        // approval_rejected_by / approval_rejected_at — who rejected and when.
        //   Set by reject(). A rejected damage is terminal (cannot be
        //   re-submitted; create a new damage instead).
        // approval_notes — free-text note from the approver/rejecter. Shared
        //   by both approve and reject (a rejection reason is the common case;
        //   an approval note is optional context). Nullable.
        Schema::table('damage_invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('damage_invoices', 'submitted_by')) {
                $table->integer('submitted_by')->nullable()->after('recovery_journal_entry_id');
            }
            if (!Schema::hasColumn('damage_invoices', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('submitted_by');
            }
            if (!Schema::hasColumn('damage_invoices', 'approved_by')) {
                $table->integer('approved_by')->nullable()->after('submitted_at');
            }
            if (!Schema::hasColumn('damage_invoices', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
            if (!Schema::hasColumn('damage_invoices', 'approval_rejected_by')) {
                $table->integer('approval_rejected_by')->nullable()->after('approved_at');
            }
            if (!Schema::hasColumn('damage_invoices', 'approval_rejected_at')) {
                $table->timestamp('approval_rejected_at')->nullable()->after('approval_rejected_by');
            }
            if (!Schema::hasColumn('damage_invoices', 'approval_notes')) {
                $table->text('approval_notes')->nullable()->after('approval_rejected_at');
            }
        });

        // ── 3. Partial index: submitted damages awaiting approval ───────
        // Powers the "awaiting my approval" worklist query for managers
        // (index page stat card + filtered list). Partial (WHERE status =
        // 'submitted') because only a small fraction of rows are in this
        // state at any time — a full index would be mostly dead entries.
        // Includes branch_id + submitted_at so the worklist can be ordered
        // by oldest-first per branch without a sort.
        if (!collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'damage_invoices' AND indexname = 'idx_dmg_submitted'"
        ))->count()) {
            DB::statement(
                "CREATE INDEX idx_dmg_submitted ON damage_invoices (branch_id, submitted_at) "
                . "WHERE status = 'submitted'"
            );
        }

        // Partial index: approved-but-not-yet-confirmed damages — the
        // "ready to post" worklist. Same rationale (small active set).
        if (!collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'damage_invoices' AND indexname = 'idx_dmg_approved_pending'"
        ))->count()) {
            DB::statement(
                "CREATE INDEX idx_dmg_approved_pending ON damage_invoices (branch_id, approved_at) "
                . "WHERE status = 'approved'"
            );
        }
    }

    public function down(): void
    {
        // Drop the partial indexes.
        DB::statement('DROP INDEX IF EXISTS idx_dmg_approved_pending');
        DB::statement('DROP INDEX IF EXISTS idx_dmg_submitted');

        // Drop the approval-workflow columns.
        Schema::table('damage_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'submitted_by', 'submitted_at',
                'approved_by', 'approved_at',
                'approval_rejected_by', 'approval_rejected_at',
                'approval_notes',
            ]);
        });

        // Restore the original (Phase 0/1/4) status CHECK.
        $this->dropStatusCheckConstraint();
        DB::statement(
            "ALTER TABLE damage_invoices "
            . "ADD CONSTRAINT damage_invoices_status_check "
            . "CHECK (status IN ('draft','confirmed','cancelled'))"
        );
    }

    /**
     * Drop the status CHECK constraint regardless of its current name.
     * PostgreSQL auto-names an inline CHECK as `{table}_{column}_check`,
     * but a prior migration may have created it with a custom name (the
     * Phase-1 migration re-created it). We introspect pg_constraint to find
     * it reliably, then drop the canonical auto-name as a fallback.
     */
    private function dropStatusCheckConstraint(): void
    {
        $constraints = DB::select(
            "SELECT conname FROM pg_constraint
             WHERE conrelid = 'damage_invoices'::regclass
               AND contype = 'c'
               AND pg_get_constraintdef(oid) ILIKE '%status IN (%'"
        );
        foreach ($constraints as $c) {
            DB::statement('ALTER TABLE damage_invoices DROP CONSTRAINT IF EXISTS ' . $c->conname);
        }
        // Fallback: drop the canonical auto-name if it still exists.
        DB::statement('ALTER TABLE damage_invoices DROP CONSTRAINT IF EXISTS damage_invoices_status_check');
    }
};
