<?php

namespace App\Services\Notification;

use App\Models\NotificationRule;
use App\Models\User;
use App\Notifications\ERPNotification;
use App\Services\Notification\ListenNotifyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Notification Service — Phase 10 (F-18b: multi-select + context-aware).
 *
 * The central dispatcher for all ERP notifications. Business modules call
 * dispatch() with an event name + context, and this service:
 *   1. Finds all active notification_rules for that event
 *   2. Resolves the recipient users for EACH recipient-type selection on
 *      the rule (a rule may carry multiple selections — F-18b), merging
 *      + de-duplicating by user ID. Context-aware selections
 *      (warehouse_manager_of_branch, salesman_of_invoice, invoice_creator)
 *      are resolved against the $context array.
 *   3. Sends the ERPNotification to each recipient via the database channel
 *   4. Increments each rule's times_fired counter
 *   5. Emits a pg_notify event so the SSE pipeline pushes a real-time toast
 *
 * Events (from NotificationRule::EVENTS) — F-18b added user_logout,
 * damage_invoice_created, branch_demand_created, customer_limit_increased.
 *
 * The User model must use the Notifiable trait (added in Phase 10).
 */
class NotificationService
{
    public function __construct(
        private ?ListenNotifyService $listenNotify = null
    ) {}

    /**
     * Event metadata: icon, color, and title template per event.
     *
     * F-18b: added the 4 new events (user_logout, damage_invoice_created,
     * branch_demand_created, customer_limit_increased).
     */
    private const EVENT_META = [
        'sales_finalize'            => ['icon' => 'fa-file-invoice-dollar', 'color' => 'success', 'title' => 'Sales Invoice Confirmed'],
        'challan_create'            => ['icon' => 'fa-truck', 'color' => 'info', 'title' => 'Challan Created'],
        'godown_create'             => ['icon' => 'fa-warehouse', 'color' => 'primary', 'title' => 'Godown Copy Created'],
        'payment_receive'           => ['icon' => 'fa-hand-holding-dollar', 'color' => 'success', 'title' => 'Payment Received'],
        'soft_delete'               => ['icon' => 'fa-trash', 'color' => 'warning', 'title' => 'Record Deleted'],
        'accounts_entry'            => ['icon' => 'fa-book', 'color' => 'primary', 'title' => 'Accounting Entry Posted'],
        'user_login'                => ['icon' => 'fa-user', 'color' => 'secondary', 'title' => 'User Login'],
        'user_logout'               => ['icon' => 'fa-right-from-bracket', 'color' => 'secondary', 'title' => 'User Logout'],
        'damage_invoice_created'    => ['icon' => 'fa-triangle-exclamation', 'color' => 'danger', 'title' => 'Damage Invoice Created'],
        'branch_demand_created'     => ['icon' => 'fa-clipboard-list', 'color' => 'info', 'title' => 'Branch Demand Created'],
        'customer_limit_increased'  => ['icon' => 'fa-arrow-up-right-dots', 'color' => 'success', 'title' => 'Customer Limit Increased'],
        // Sales return events
        'return_created'            => ['icon' => 'fa-arrow-rotate-left', 'color' => 'info', 'title' => 'Sales Return Created'],
        'return_confirmed'          => ['icon' => 'fa-check', 'color' => 'primary', 'title' => 'Sales Return Confirmed'],
        'return_reversed'           => ['icon' => 'fa-rotate-left', 'color' => 'danger', 'title' => 'Sales Return Reversed'],
    ];

    /**
     * Dispatch a notification for an event.
     *
     * @param string $event    Event name (from NotificationRule::EVENTS)
     * @param string $body     Human-readable description
     * @param string|null $referenceType e.g. 'sales_invoice'
     * @param int|null $referenceId      e.g. invoice ID
     * @param array $extra    Extra data for the notification title/body
     *                        (recognized key: 'title').
     * @param array $context  Event context for context-aware recipient
     *                        resolution (F-18b). Recognized keys:
     *                          - branch_id  (int)   → warehouse_manager_of_branch
     *                          - salesman_id (int)  → salesman_of_invoice (employee id)
     *                          - created_by (int)   → invoice_creator (user id)
     *                          - customer_id (int)  → reserved for future use
     * @return int Number of notifications sent
     */
    public function dispatch(string $event, string $body, ?string $referenceType = null, ?int $referenceId = null, array $extra = [], array $context = []): int
    {
        // Find active rules for this event (eager-load recipient selections).
        $rules = NotificationRule::active()
            ->forEvent($event)
            ->with('recipientTypes')
            ->get();

        if ($rules->isEmpty()) {
            return 0;
        }

        $meta  = self::EVENT_META[$event] ?? ['icon' => 'fa-bell', 'color' => 'primary', 'title' => ucfirst(str_replace('_', ' ', $event))];
        $title = $extra['title'] ?? $meta['title'];

        $sentCount = 0;

        foreach ($rules as $rule) {
            // Resolve recipients across ALL recipient-type selections on
            // this rule (F-18b multi-select) + de-duplicate by user ID.
            $recipients = $this->resolveRecipients($rule, $context);

            if ($recipients->isEmpty()) {
                continue;
            }

            // F-18b: database-only channel (broadcast channel removed).
            $channels = ['database'];

            foreach ($recipients as $user) {
                $user->notify(new ERPNotification(
                    title: $title,
                    body: $body,
                    event: $event,
                    referenceType: $referenceType,
                    referenceId: $referenceId,
                    icon: $meta['icon'],
                    color: $meta['color'],
                    channels: $channels,
                ));
                $sentCount++;
            }

            $rule->increment('times_fired');

            Log::info('Notification dispatched', [
                'rule_id'      => $rule->id,
                'rule_name'    => $rule->name,
                'event'        => $event,
                'recipients'   => $recipients->count(),
                'channels'     => $channels,
                'context_keys' => array_keys($context),
            ]);

            // Phase 1E (Task 31): Emit pg_notify for real-time LISTEN/NOTIFY.
            // This allows the ListenNotifyWorker to pick up application-level
            // notification events and push them to SSE clients immediately,
            // bypassing the 30-second AJAX polling delay.
            if ($this->listenNotify) {
                try {
                    $this->listenNotify->emitNotify('rcerp_notification_dispatched', [
                        'table'     => 'notifications',
                        'action'    => 'INSERT',
                        'id'        => 0, // No specific row ID
                        'branch_id' => $context['branch_id'] ?? $recipients->first()?->employee?->branch_id,
                        'changes'   => [
                            'event'           => $event,
                            'rule_id'         => $rule->id,
                            'rule_name'       => $rule->name,
                            'recipient_count' => $recipients->count(),
                            'title'           => $title,
                            'body'            => $body,
                            'reference_type'  => $referenceType,
                            'reference_id'    => $referenceId,
                            'icon'            => $meta['icon'],
                            'color'           => $meta['color'],
                        ],
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('NotificationService: pg_notify emission failed', [
                        'event' => $event,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $sentCount;
    }

    /**
     * Resolve the recipient users for a rule.
     *
     * F-18b: iterates every recipient-type selection on the rule (the
     * notification_rule_recipients pivot), resolves each to a set of
     * users, and merges + de-duplicates by user ID. Context-aware
     * selections are resolved against $context.
     *
     * @param NotificationRule $rule
     * @param array $context  Event context (branch_id, salesman_id, created_by, …)
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function resolveRecipients(NotificationRule $rule, array $context = []): \Illuminate\Support\Collection
    {
        if ($rule->recipientTypes->isEmpty()) {
            return collect();
        }

        // Base scope: active, non-deleted users (matches the original
        // Phase-10 behavior of filtering on the USER, not the employee).
        $baseUserQuery = fn () => User::where('is_active', true)->whereNull('deleted_at');

        $resolved = collect();

        foreach ($rule->recipientTypes as $selection) {
            $users = match ($selection->recipient_type) {
                'admin'       => $baseUserQuery()->whereHas('employee', fn($q) => $q->whereIn('role', ['admin', 'superadmin']))->get(),
                'superadmin'  => $baseUserQuery()->whereHas('employee', fn($q) => $q->where('role', 'superadmin'))->get(),

                // F-18b: un-fused — sales_manager now means ONLY manager + salesman
                // (was previously over-broad to include admin/superadmin).
                'sales_manager'     => $baseUserQuery()->whereHas('employee', fn($q) => $q->whereIn('role', ['manager', 'salesman']))->get(),
                'accountant'        => $baseUserQuery()->whereHas('employee', fn($q) => $q->whereIn('role', ['accountant', 'admin', 'superadmin']))->get(),

                // Warehouse managers across ALL branches (global).
                'warehouse_manager' => $baseUserQuery()->whereHas('employee', fn($q) => $q->where('role', 'warehouse_manager'))->get(),

                // — Context-aware selections (F-18b) —
                // Warehouse managers of the event's branch only.
                'warehouse_manager_of_branch' => !empty($context['branch_id'])
                    ? $baseUserQuery()->whereHas('employee', fn($q) => $q->where('role', 'warehouse_manager')->where('branch_id', $context['branch_id']))->get()
                    : collect(),

                // The salesman (employee) tied to the invoice / event.
                'salesman_of_invoice' => !empty($context['salesman_id'])
                    ? $baseUserQuery()->whereHas('employee', fn($q) => $q->where('id', $context['salesman_id']))->get()
                    : collect(),

                // The user who created the record (context: created_by = user id).
                'invoice_creator' => !empty($context['created_by'])
                    ? $baseUserQuery()->where('id', $context['created_by'])->get()
                    : collect(),

                'all_users' => $baseUserQuery()->get(),

                'specific_user' => $selection->recipient_user_id
                    ? $baseUserQuery()->where('id', $selection->recipient_user_id)->get()
                    : collect(),

                default => collect(),
            };

            $resolved = $resolved->merge($users);
        }

        // De-duplicate by user ID (a user matching two selections on the
        // same rule should receive the notification only once).
        return $resolved->unique('id')->values();
    }

    /**
     * Get notification stats for the admin dashboard.
     *
     * @return array
     */
    public function getStats(): array
    {
        return [
            'total_rules'         => NotificationRule::count(),
            'active_rules'        => NotificationRule::active()->count(),
            'total_sent'          => NotificationRule::sum('times_fired'),
            'total_notifications' => DB::table('notifications')->count(),
            'unread_notifications'=> DB::table('notifications')->whereNull('read_at')->count(),
            'rules_by_event'      => NotificationRule::select('event', DB::raw('COUNT(*) as count'), DB::raw('SUM(times_fired) as fired'))
                ->groupBy('event')->pluck('fired', 'event')->toArray(),
        ];
    }
}
