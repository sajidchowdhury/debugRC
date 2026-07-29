<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4 — F-18d: Default notification rules.
 *
 * Seeds a sensible default rule for each of the 9 predefined business
 * events (+ the 2 sales-return sub-flows that are already dispatched by
 * SalesReturnService). Each default rule targets a multi-select of
 * recipient types stored in the `notification_rule_recipients` pivot —
 * e.g. "After Sales Confirm → [Admin, Warehouse Manager of branch,
 * Salesman of invoice]".
 *
 * Invokable two ways:
 *   1. `php artisan db:seed --class=NotificationRuleSeeder` (first-time
 *      deploy) — idempotent: skips any event+name combo that already
 *      exists, so re-running never duplicates.
 *   2. The "Reset to defaults" button on the admin rules page
 *      (NotificationController::resetDefaults) — the controller
 *      hard-deletes every existing rule first (cascade clears the
 *      pivot), then calls run() for a clean default set.
 *
 * Default rules use the " — default" name suffix so admins can tell them
 * apart from custom rules they create. `created_by` is left NULL because
 * these are system defaults (the seeder has no authenticated user
 * context; the reset button attributes are implicit).
 *
 * Context-aware recipient types (★ — warehouse_manager_of_branch,
 * salesman_of_invoice, invoice_creator) only resolve when the dispatcher
 * passes the matching $context key. F-18c wired $context into all 8
 * active event trigger points, so every context-aware selection below
 * will resolve at dispatch time.
 */
class NotificationRuleSeeder extends Seeder
{
    /**
     * Default rules: [event, name, description, [recipient_type, ...]].
     */
    private const DEFAULTS = [
        [
            'event'    => 'sales_finalize',
            'name'     => 'After Sales Confirm — default',
            'desc'     => 'Fires when a sales invoice is finalized. Notifies admins, the warehouse manager of the event branch, and the invoice salesman.',
            'types'    => ['admin', 'warehouse_manager_of_branch', 'salesman_of_invoice'],
        ],
        [
            'event'    => 'challan_create',
            'name'     => 'After Create Challan Copy — default',
            'desc'     => 'Fires when a sales challan is issued. Notifies admins and the warehouse manager of the event branch.',
            'types'    => ['admin', 'warehouse_manager_of_branch'],
        ],
        [
            'event'    => 'user_login',
            'name'     => 'After Login — default',
            'desc'     => 'Fires when any user logs in. Notifies admins.',
            'types'    => ['admin'],
        ],
        [
            'event'    => 'user_logout',
            'name'     => 'After Logout — default',
            'desc'     => 'Fires when any user logs out. Notifies admins.',
            'types'    => ['admin'],
        ],
        [
            'event'    => 'damage_invoice_created',
            'name'     => 'After Create Damage Invoice — default',
            'desc'     => 'Fires when a damage invoice is created. Notifies admins, the warehouse manager of the event branch, and accountants.',
            'types'    => ['admin', 'warehouse_manager_of_branch', 'accountant'],
        ],
        [
            'event'    => 'payment_receive',
            'name'     => 'After Receive Money — default',
            'desc'     => 'Fires when a customer payment (receive) is confirmed. Notifies admins and accountants.',
            'types'    => ['admin', 'accountant'],
        ],
        [
            'event'    => 'return_created',
            'name'     => 'After Sales Return — default',
            'desc'     => 'Fires when a sales return is created. Notifies admins.',
            'types'    => ['admin'],
        ],
        [
            'event'    => 'return_confirmed',
            'name'     => 'Sales Return Confirmed — default',
            'desc'     => 'Fires when a sales return is confirmed. Notifies admins, the warehouse manager of the event branch, and accountants.',
            'types'    => ['admin', 'warehouse_manager_of_branch', 'accountant'],
        ],
        [
            'event'    => 'return_reversed',
            'name'     => 'Sales Return Reversed — default',
            'desc'     => 'Fires when a sales return is reversed. Notifies admins and accountants.',
            'types'    => ['admin', 'accountant'],
        ],
        [
            'event'    => 'branch_demand_created',
            'name'     => 'After Branch Demand — default',
            'desc'     => 'Fires when a branch demand is created. Notifies admins and the warehouse manager of the event branch. NOTE: no Laravel creation path exists yet — this rule is ready for when a BranchDemandService is built.',
            'types'    => ['admin', 'warehouse_manager_of_branch'],
        ],
        [
            'event'    => 'customer_limit_increased',
            'name'     => 'After Increasing Customer Limit — default',
            'desc'     => 'Fires when a customer credit limit is increased. Notifies admins and accountants.',
            'types'    => ['admin', 'accountant'],
        ],
    ];

    /**
     * Run the seeder. Idempotent by (event, name) — safe to re-run.
     */
    public function run(): void
    {
        $now = now();

        foreach (self::DEFAULTS as $rule) {
            // Skip if a rule with this exact event+name already exists
            // (idempotent re-run; the reset button deletes everything
            // first so this check always passes there).
            $exists = DB::table('notification_rules')
                ->where('event', $rule['event'])
                ->where('name', $rule['name'])
                ->exists();

            if ($exists) {
                continue;
            }

            $ruleId = DB::table('notification_rules')->insertGetId([
                'name'        => $rule['name'],
                'event'       => $rule['event'],
                'channel'     => 'database', // F-18b: database-only
                'is_active'   => true,
                'times_fired' => 0,
                'description' => $rule['desc'],
                'created_by'  => null, // system default — no user context
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);

            // Multi-select recipient types → pivot rows (F-18b schema).
            $rows = [];
            foreach ($rule['types'] as $type) {
                $rows[] = [
                    'notification_rule_id' => $ruleId,
                    'recipient_type'       => $type,
                    'recipient_user_id'    => null, // only set for specific_user (none in defaults)
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ];
            }
            DB::table('notification_rule_recipients')->insert($rows);
        }
    }
}
