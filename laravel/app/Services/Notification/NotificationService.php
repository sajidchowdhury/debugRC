<?php

namespace App\Services\Notification;

use App\Models\NotificationRule;
use App\Models\User;
use App\Notifications\ERPNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Notification Service — Phase 10.
 *
 * The central dispatcher for all ERP notifications. Business modules call
 * dispatch() with an event name + context, and this service:
 *   1. Finds all active notification_rules for that event
 *   2. Resolves the recipient users (by role or specific user)
 *   3. Sends the ERPNotification to each recipient via the rule's channel
 *   4. Increments the rule's times_fired counter
 *
 * Events (from NotificationRule::EVENTS):
 *   sales_finalize, challan_create, godown_create, payment_receive,
 *   soft_delete, accounts_entry, user_login
 *
 * The User model must use the Notifiable trait (added in Phase 10).
 */
class NotificationService
{
    /**
     * Event metadata: icon, color, and title template per event.
     */
    private const EVENT_META = [
        'sales_finalize' => ['icon' => 'fa-file-invoice-dollar', 'color' => 'success', 'title' => 'Sales Invoice Finalized'],
        'challan_create' => ['icon' => 'fa-truck', 'color' => 'info', 'title' => 'Challan Created'],
        'godown_create' => ['icon' => 'fa-warehouse', 'color' => 'primary', 'title' => 'Godown Copy Created'],
        'payment_receive' => ['icon' => 'fa-hand-holding-dollar', 'color' => 'success', 'title' => 'Payment Received'],
        'soft_delete' => ['icon' => 'fa-trash', 'color' => 'warning', 'title' => 'Record Deleted'],
        'accounts_entry' => ['icon' => 'fa-book', 'color' => 'primary', 'title' => 'Accounting Entry Posted'],
        'user_login' => ['icon' => 'fa-user', 'color' => 'secondary', 'title' => 'User Login'],
    ];

    /**
     * Dispatch a notification for an event.
     *
     * @param string $event Event name (from NotificationRule::EVENTS)
     * @param string $body Human-readable description
     * @param string|null $referenceType e.g. 'sales_invoice'
     * @param int|null $referenceId e.g. invoice ID
     * @param array $extra Extra data for the notification title/body
     * @return int Number of notifications sent
     */
    public function dispatch(string $event, string $body, ?string $referenceType = null, ?int $referenceId = null, array $extra = []): int
    {
        // Find active rules for this event.
        $rules = NotificationRule::active()->forEvent($event)->get();

        if ($rules->isEmpty()) {
            return 0;
        }

        $meta = self::EVENT_META[$event] ?? ['icon' => 'fa-bell', 'color' => 'primary', 'title' => ucfirst(str_replace('_', ' ', $event))];
        $title = $extra['title'] ?? $meta['title'];

        $sentCount = 0;

        foreach ($rules as $rule) {
            // Resolve recipients.
            $recipients = $this->resolveRecipients($rule);

            if ($recipients->isEmpty()) {
                continue;
            }

            // Determine channels.
            $channels = match ($rule->channel) {
                'broadcast' => ['broadcast'],
                'both' => ['database', 'broadcast'],
                default => ['database'],
            };

            // Send to each recipient.
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

            // Increment times_fired.
            $rule->increment('times_fired');

            Log::info('Notification dispatched', [
                'rule_id' => $rule->id,
                'rule_name' => $rule->name,
                'event' => $event,
                'recipients' => $recipients->count(),
                'channels' => $channels,
            ]);
        }

        return $sentCount;
    }

    /**
     * Resolve the recipient users for a rule.
     *
     * @param NotificationRule $rule
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function resolveRecipients(NotificationRule $rule): \Illuminate\Support\Collection
    {
        return match ($rule->recipient_type) {
            'admin' => User::whereHas('employee', fn($q) => $q->whereIn('role', ['admin', 'superadmin']))
                ->where('is_active', true)->whereNull('deleted_at')->get(),
            'superadmin' => User::whereHas('employee', fn($q) => $q->where('role', 'superadmin'))
                ->where('is_active', true)->whereNull('deleted_at')->get(),
            'sales_manager' => User::whereHas('employee', fn($q) => $q->whereIn('role', ['manager', 'salesman', 'admin', 'superadmin']))
                ->where('is_active', true)->whereNull('deleted_at')->get(),
            'accountant' => User::whereHas('employee', fn($q) => $q->whereIn('role', ['accountant', 'admin', 'superadmin']))
                ->where('is_active', true)->whereNull('deleted_at')->get(),
            'all_users' => User::where('is_active', true)->whereNull('deleted_at')->get(),
            'specific_user' => $rule->recipient_user_id
                ? User::where('id', $rule->recipient_user_id)->where('is_active', true)->whereNull('deleted_at')->get()
                : collect(),
            default => collect(),
        };
    }

    /**
     * Get notification stats for the admin dashboard.
     *
     * @return array
     */
    public function getStats(): array
    {
        return [
            'total_rules' => NotificationRule::count(),
            'active_rules' => NotificationRule::active()->count(),
            'total_sent' => NotificationRule::sum('times_fired'),
            'total_notifications' => DB::table('notifications')->count(),
            'unread_notifications' => DB::table('notifications')->whereNull('read_at')->count(),
            'rules_by_event' => NotificationRule::select('event', DB::raw('COUNT(*) as count'), DB::raw('SUM(times_fired) as fired'))
                ->groupBy('event')->pluck('fired', 'event')->toArray(),
        ];
    }
}
