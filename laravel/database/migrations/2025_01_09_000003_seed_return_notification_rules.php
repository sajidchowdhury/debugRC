<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P1-7 — Seed notification_rules for the 3 new sales return events.
 *
 * Legacy had 5 sales-specific notification events including return_created +
 * return_received. Laravel had 4 sales events (sales_finalize, challan_create,
 * godown_create, payment_receive) but NO return events.
 *
 * This migration seeds the 3 new return event rules:
 *   - return_created → notify admins (legacy: notifyInvoiceCreated → admins)
 *   - return_confirmed → notify warehouse_manager + accountant (legacy:
 *     notifyReturnReceived → admins + branch warehouse_manager + confirming user)
 *   - return_reversed → notify accountant + admin (legacy pattern)
 *
 * All rules default to 'database' channel (in-app notifications). Admins can
 * toggle to 'broadcast' (live WebSocket) or 'both' via the notification rules UI.
 *
 * NOTE (2026-07-22): Telegram/FCM channels were removed per user request (R24/R25
 * dropped). Only 'database' and 'broadcast' channels are now used.
 *
 * SCHEMA-AWARE (2026-07-31):
 *   The original schema stored `recipient_type` directly on `notification_rules`.
 *   Migration 2025_01_26_000001_notification_rules_multi_recipients moved it
 *   into a pivot table `notification_rule_recipients` (one rule → many types)
 *   and DROPPED the `recipient_type` column from `notification_rules`.
 *
 *   Because this seed migration may be re-run AFTER that schema change
 *   (e.g. via `db:reseed-basic`), we detect the schema version at runtime:
 *     - If `recipient_type` column still exists on `notification_rules`
 *       → OLD schema: insert recipient_type inline.
 *     - Otherwise → NEW schema: insert the rule WITHOUT recipient_type,
 *       then add pivot rows to `notification_rule_recipients`.
 *
 * Idempotent: checks if rules already exist before inserting.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rules = [
            [
                'name' => 'Sales Return Created — Notify Admins',
                'event' => 'return_created',
                'recipient_type' => 'admin',
                'recipient_user_id' => null,
                'channel' => 'database',
                'is_active' => true,
            ],
            [
                'name' => 'Sales Return Confirmed — Notify Warehouse Managers',
                'event' => 'return_confirmed',
                'recipient_type' => 'warehouse_manager',
                'recipient_user_id' => null,
                'channel' => 'database',
                'is_active' => true,
            ],
            [
                'name' => 'Sales Return Confirmed — Notify Accountants',
                'event' => 'return_confirmed',
                'recipient_type' => 'accountant',
                'recipient_user_id' => null,
                'channel' => 'database',
                'is_active' => true,
            ],
            [
                'name' => 'Sales Return Reversed — Notify Accountants',
                'event' => 'return_reversed',
                'recipient_type' => 'accountant',
                'recipient_user_id' => null,
                'channel' => 'database',
                'is_active' => true,
            ],
        ];

        // Detect schema version: does notification_rules still have recipient_type?
        $hasRecipientColumn = Schema::hasColumn('notification_rules', 'recipient_type');

        foreach ($rules as $rule) {
            if ($hasRecipientColumn) {
                // ── OLD SCHEMA: recipient_type lives on notification_rules ──
                $exists = DB::table('notification_rules')
                    ->where('event', $rule['event'])
                    ->where('recipient_type', $rule['recipient_type'])
                    ->exists();

                if (!$exists) {
                    DB::table('notification_rules')->insert(array_merge($rule, [
                        'times_fired' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]));
                }
            } else {
                // ── NEW SCHEMA: recipient_type lives in notification_rule_recipients ──
                // Check if a rule with this name already exists (names are unique
                // enough for seeding purposes; the pivot handles the multi-type case).
                $existing = DB::table('notification_rules')
                    ->where('name', $rule['name'])
                    ->first();

                if ($existing) {
                    // Rule already seeded — ensure the pivot row exists too.
                    $this->ensurePivotRow($existing->id, $rule['recipient_type'], $rule['recipient_user_id']);
                } else {
                    // Insert the rule WITHOUT recipient_type (column no longer exists).
                    $ruleId = DB::table('notification_rules')->insertGetId([
                        'name'    => $rule['name'],
                        'event'   => $rule['event'],
                        'channel' => $rule['channel'],
                        'is_active' => $rule['is_active'],
                        'times_fired' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $this->ensurePivotRow($ruleId, $rule['recipient_type'], $rule['recipient_user_id']);
                }
            }
        }
    }

    /**
     * Insert a pivot row into notification_rule_recipients (idempotent).
     * Only called on the NEW schema where the pivot table exists.
     */
    private function ensurePivotRow(int $ruleId, string $recipientType, ?int $recipientUserId): void
    {
        $already = DB::table('notification_rule_recipients')
            ->where('notification_rule_id', $ruleId)
            ->where('recipient_type', $recipientType)
            ->exists();

        if (!$already) {
            DB::table('notification_rule_recipients')->insert([
                'notification_rule_id' => $ruleId,
                'recipient_type'       => $recipientType,
                'recipient_user_id'    => $recipientUserId,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('notification_rules')
            ->whereIn('event', ['return_created', 'return_confirmed', 'return_reversed'])
            ->delete();
    }
};
