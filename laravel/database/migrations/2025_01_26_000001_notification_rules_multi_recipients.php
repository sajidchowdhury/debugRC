<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — F-18b: Multi-select recipient types per event.
 *
 * The original Phase-10 schema stored ONE recipient_type (single string
 * column) per notification_rules row. The user's redefined F-18 requires
 * multi-select of recipient types per event — e.g. "After Sales Confirm →
 * notify [Admin + Warehouse Manager of branch + Salesman of invoice]".
 *
 * This migration:
 *   1. Creates a pivot table `notification_rule_recipients` that holds the
 *      (rule_id, recipient_type, recipient_user_id) tuples — one rule may
 *      now have many recipient-type selections.
 *   2. Backfills every existing notification_rules row into the pivot
 *      (carrying its single recipient_type + recipient_user_id forward),
 *      so no configured rule is lost.
 *   3. Normalizes the legacy `warehouse_manager` recipient_type key into
 *      `warehouse_manager` (kept as canonical "all branches") — no rename
 *      needed; the new context-aware key is `warehouse_manager_of_branch`.
 *   4. Drops the now-redundant `recipient_type` + `recipient_user_id`
 *      columns (and the recipient_type index) from notification_rules.
 *   5. Collapses the vestigial `broadcast`/`both` channels to `database`
 *      (no config/broadcasting.php exists in the app; ERPNotification no
 *      longer ships a broadcast channel after F-18b). Any rule previously
 *      set to `broadcast` or `both` becomes `database`.
 *
 * Rollback restores the single-recipient columns and re-derives them from
 * the first pivot row per rule (best-effort; multi-recipient rules lose
 * the extra selections on rollback — acceptable since the feature is new).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Pivot table for multi-select recipient types per rule.
        if (!Schema::hasTable('notification_rule_recipients')) {
            Schema::create('notification_rule_recipients', function (Blueprint $table) {
                $table->id();
                $table->foreignId('notification_rule_id')
                      ->constrained('notification_rules')
                      ->cascadeOnDelete();
                $table->string('recipient_type'); // admin, warehouse_manager_of_branch, salesman_of_invoice, specific_user, etc.
                $table->integer('recipient_user_id')->nullable(); // only for specific_user
                $table->timestamps();

                $table->index(['notification_rule_id', 'recipient_type']);
                $table->index('recipient_type');
            });
        }

        // 2. Backfill existing single-recipient rules into the pivot.
        //    Carries recipient_type + recipient_user_id forward verbatim.
        if (Schema::hasColumn('notification_rules', 'recipient_type')) {
            $existing = DB::table('notification_rules')
                ->select(['id', 'recipient_type', 'recipient_user_id'])
                ->whereNotNull('recipient_type')
                ->where('recipient_type', '!=', '')
                ->get();

            $now = now();
            foreach ($existing as $rule) {
                // Avoid duplicate backfill if this migration is re-run.
                $already = DB::table('notification_rule_recipients')
                    ->where('notification_rule_id', $rule->id)
                    ->where('recipient_type', $rule->recipient_type)
                    ->exists();
                if ($already) {
                    continue;
                }
                DB::table('notification_rule_recipients')->insert([
                    'notification_rule_id' => $rule->id,
                    'recipient_type'       => $rule->recipient_type,
                    'recipient_user_id'    => $rule->recipient_user_id,
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ]);
            }
        }

        // 3. Collapse vestigial broadcast/both channels → database.
        //    No config/broadcasting.php exists; ERPNotification is
        //    database-only after F-18b. Keeps the column + index (harmless),
        //    just normalizes the values.
        DB::table('notification_rules')
            ->whereIn('channel', ['broadcast', 'both'])
            ->update(['channel' => 'database']);

        // 4. Drop the redundant single-recipient columns.
        //    PostgreSQL automatically drops the recipient_type index when
        //    its column is dropped, so no explicit dropIndex is needed.
        if (Schema::hasColumn('notification_rules', 'recipient_type')) {
            Schema::table('notification_rules', function (Blueprint $table) {
                $table->dropColumn(['recipient_type', 'recipient_user_id']);
            });
        }
    }

    public function down(): void
    {
        // Restore the single-recipient columns.
        if (!Schema::hasColumn('notification_rules', 'recipient_type')) {
            Schema::table('notification_rules', function (Blueprint $table) {
                $table->string('recipient_type')->nullable()->after('event');
                $table->integer('recipient_user_id')->nullable()->after('recipient_type');
                $table->index('recipient_type');
            });
        }

        // Best-effort: re-derive recipient_type/recipient_user_id from the
        // FIRST pivot row of each rule (multi-recipient rules lose extras).
        if (Schema::hasTable('notification_rule_recipients')) {
            $rows = DB::table('notification_rule_recipients')
                ->orderBy('notification_rule_id')
                ->orderBy('id')
                ->get()
                ->unique('notification_rule_id');

            foreach ($rows as $row) {
                DB::table('notification_rules')
                    ->where('id', $row->notification_rule_id)
                    ->update([
                        'recipient_type'    => $row->recipient_type,
                        'recipient_user_id' => $row->recipient_user_id,
                    ]);
            }
        }

        Schema::dropIfExists('notification_rule_recipients');
    }
};
