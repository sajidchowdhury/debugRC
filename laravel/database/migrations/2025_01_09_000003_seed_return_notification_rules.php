<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P1-7 — Seed notification_rules for the 3 new sales return events.
 *
 * Legacy had 5 sales-specific Telegram events including return_created +
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

        foreach ($rules as $rule) {
            // Check if this rule already exists (idempotent).
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
        }
    }

    public function down(): void
    {
        DB::table('notification_rules')
            ->whereIn('event', ['return_created', 'return_confirmed', 'return_reversed'])
            ->delete();
    }
};
