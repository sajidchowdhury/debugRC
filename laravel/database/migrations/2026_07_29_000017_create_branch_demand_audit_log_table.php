<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branch Demand Audit Log — Phase 8 (Anti-Gaming & Accountability Controls).
 *
 * Immutable audit trail for every state transition in the Branch Demand lifecycle.
 * Follows the StockAdjustmentAuditLogger pattern: one row per action, written
 * inside the same DB::transaction as the data change, so rolled-back operations
 * also roll back their audit rows.
 *
 * The table is NOT an event-sourcing store — it is a forensic record. It does
 * NOT throw on validation errors; it logs what it can. The data change is the
 * source of truth; the audit row is the forensic supplement.
 *
 * RLS policies mirror the stock_adjustment_audit_log pattern:
 *   - Branch-scoped read: users see only their branch's audit rows
 *   - Admin bypass: admins see all rows
 *   - Insert: any authenticated user can insert (the service writes the row)
 *   - No UPDATE / DELETE: the audit trail is append-only
 *
 * Indexes:
 *   - idx_bdal_demand:       lookup by demand (most common query)
 *   - idx_bdal_branch:       RLS + branch-scoped listing
 *   - idx_bdal_actor:        who-did-what queries
 *   - idx_bdal_critical:     partial index on high-severity actions
 *     (reverse, delete, reprice, settlement_reverse) for quick forensic triage
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_demand_audit_log', function (Blueprint $table) {
            $table->id();

            // The demand being acted on
            $table->unsignedBigInteger('branch_demand_id');
            $table->foreign('branch_demand_id', 'fk_bdal_demand')
                ->references('id')->on('branch_demands')
                ->cascadeOnDelete();

            // Denormalized from the demand for RLS — avoids a join when
            // scoping reads by branch. Set to the from_branch_id (requester)
            // since the requester is the primary stakeholder.
            $table->unsignedBigInteger('branch_id')->nullable();

            // CHECK-constrained action enum. Must cover every state transition
            // in the Branch Demand lifecycle.
            //   create|send|confirm_receipt|reverse|delete|reject|
            //   reprice|settle|settlement_reverse|export|print
            $table->string('action', 40);

            // Who performed the action
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_role', 50)->nullable();

            // Snapshot of the action context (jsonb for flexible schema)
            // Examples:
            //   create:  { demand_code, from_branch_id, to_branch_id, items_count, total_value }
            //   send:    { total_value, warehouse_transfer_id, items: [...] }
            //   reverse: { reason, stock_reversed_count }
            //   reprice: { original_total, new_total, adjustment_amount, reason, approved_by }
            //   settle:  { amount, source, settlement_type }
            $table->jsonb('payload')->nullable();

            // Request context — only available in HTTP requests
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamp('created_at', 0)->default(DB::raw('CURRENT_TIMESTAMP'));
        });

        // CHECK constraint for action enum
        DB::statement("
            ALTER TABLE branch_demand_audit_log
            ADD CONSTRAINT chk_bdal_action
            CHECK (action IN (
                'create', 'send', 'confirm_receipt', 'reverse', 'delete',
                'reject', 'reprice', 'settle', 'settlement_reverse',
                'export', 'print'
            ))
        ");

        // Indexes
        DB::statement('CREATE INDEX idx_bdal_demand  ON branch_demand_audit_log (branch_demand_id)');
        DB::statement('CREATE INDEX idx_bdal_branch  ON branch_demand_audit_log (branch_id)');
        DB::statement('CREATE INDEX idx_bdal_actor   ON branch_demand_audit_log (actor_id)');
        // Partial index on high-severity actions for quick forensic triage
        DB::statement("
            CREATE INDEX idx_bdal_critical ON branch_demand_audit_log (branch_demand_id, action)
            WHERE action IN ('reverse', 'delete', 'reprice', 'settlement_reverse')
        ");

        // RLS
        DB::statement('ALTER TABLE branch_demand_audit_log ENABLE ROW LEVEL SECURITY');

        // Branch-scoped read: users see only their branch's audit rows
        DB::statement("
            CREATE POLICY bdal_branch_read ON branch_demand_audit_log
                FOR SELECT
                USING (branch_id = current_setting('app.current_branch_id')::bigint
                       OR current_setting('app.is_admin', true) = 'true')
        ");

        // Admin bypass: admins see all rows
        DB::statement("
            CREATE POLICY bdal_admin_read ON branch_demand_audit_log
                FOR SELECT
                USING (current_setting('app.is_admin', true) = 'true')
        ");

        // Insert: any authenticated user can insert (the service writes the row)
        DB::statement("
            CREATE POLICY bdal_insert ON branch_demand_audit_log
                FOR INSERT
                WITH CHECK (true)
        ");

        // No UPDATE / DELETE — the audit trail is append-only
        // (RLS default-deny handles this, but explicit policies make intent clear)
        DB::statement("
            CREATE POLICY bdal_no_update ON branch_demand_audit_log
                FOR UPDATE
                USING (false)
        ");

        DB::statement("
            CREATE POLICY bdal_no_delete ON branch_demand_audit_log
                FOR DELETE
                USING (false)
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_demand_audit_log');
    }
};
