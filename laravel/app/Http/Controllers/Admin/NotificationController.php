<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNotificationRuleRequest;
use App\Http\Requests\UpdateNotificationRuleRequest;
use App\Models\NotificationRule;
use App\Models\NotificationRuleRecipient;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Database\Seeders\NotificationRuleSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Notification Controller — Phase 10 (F-18b: multi-select recipients).
 *
 * Admin can:
 *   - View/create/edit/delete notification rules (when + WHO — multi-select
 *     of recipient types per event, F-18b) — admin/superadmin only (F-18a).
 *   - View the notification inbox (their own notifications) — all auth users.
 *   - Mark notifications as read — all auth users.
 *   - View stats (total sent, active rules, etc.).
 */
class NotificationController extends Controller
{
    public function __construct(
        private NotificationService $notificationService
    ) {}

    /**
     * Notification rules management page (CRUD + stats).
     */
    public function rules(Request $request)
    {
        $rules = NotificationRule::with(['creator', 'recipientTypes.recipientUser'])
            ->when($request->input('event'), fn($q, $e) => $q->where('event', $e))
            ->when($request->input('recipient_type'), function ($q, $r) {
                // F-18b: recipient_type now lives on the pivot — filter via whereHas.
                $q->whereHas('recipientTypes', fn($q2) => $q2->where('recipient_type', $r));
            })
            ->when($request->boolean('active_only'), fn($q) => $q->where('is_active', true))
            ->orderBy('id', 'desc')
            ->paginate(25);

        $stats = $this->notificationService->getStats();
        $users = User::where('is_active', true)->whereNull('deleted_at')->orderBy('username')->get();

        return view('admin.notifications.rules', [
            'title'            => 'Notification Rules',
            'rules'            => $rules,
            'stats'            => $stats,
            'users'            => $users,
            'events'           => NotificationRule::EVENTS,
            'recipients'       => NotificationRule::RECIPIENTS,
            'channels'         => NotificationRule::CHANNELS,
            'contextAware'     => NotificationRule::CONTEXT_AWARE_RECIPIENTS,
            'filters'          => $request->only(['event', 'recipient_type', 'active_only']),
        ]);
    }

    /**
     * Store a new notification rule (F-18b: multi-select recipient types).
     *
     * WORKFLOWS-AUDIT-2 (G-184): validation moved into the typed
     * StoreNotificationRuleRequest FormRequest (mirrors the pattern
     * established by the sibling accounting FormRequests + the
     * WORKFLOWS-AUDIT-1 approval FormRequests).
     */
    public function storeRule(StoreNotificationRuleRequest $request)
    {
        $payload = $request->toServicePayload();
        $recipientTypes = $payload['recipient_types'];

        // If specific_user is among the selections, a recipient_user_id is required.
        // (Checked here rather than in the FormRequest so the error message can
        // reference the recipient_type context — the FormRequest can't tell
        // which selection triggered the requirement.)
        if (in_array('specific_user', $recipientTypes, true) && empty($payload['recipient_user_id'])) {
            return back()->withInput()->with('error', 'Specific User recipient requires a user selection.');
        }

        DB::transaction(function () use ($payload, $recipientTypes, &$rule) {
            $rule = NotificationRule::create([
                'name'        => $payload['name'],
                'event'       => $payload['event'],
                'channel'     => 'database', // F-18b: database-only (broadcast removed)
                'is_active'   => $payload['is_active'],
                'description' => $payload['description'],
                'created_by'  => auth()->id(),
            ]);

            // Sync the multi-select recipient types to the pivot (F-18b).
            $this->syncRecipientTypes($rule->id, $recipientTypes, $payload['recipient_user_id']);
        });

        return redirect()->route('admin.notifications.rules')
            ->with('success', "Rule '{$payload['name']}' created.");
    }

    /**
     * Update an existing notification rule (F-18b: multi-select recipients).
     *
     * WORKFLOWS-AUDIT-2 (G-184): NEW — previously no `updateRule` route
     * existed (rules could only be created/toggled/deleted, never edited).
     * Admins had to delete + recreate a rule to change its name/event/
     * recipients/description, losing `times_fired` history + `created_at` +
     * `created_by`. This method does a FULL replacement of the editable
     * fields + re-syncs the pivot (delete old recipient types, insert new),
     * preserving `times_fired`, `created_at`, `created_by`.
     */
    public function updateRule(int $id, UpdateNotificationRuleRequest $request)
    {
        $rule = NotificationRule::findOrFail($id);
        $payload = $request->toServicePayload();
        $recipientTypes = $payload['recipient_types'];

        if (in_array('specific_user', $recipientTypes, true) && empty($payload['recipient_user_id'])) {
            return back()->withInput()->with('error', 'Specific User recipient requires a user selection.');
        }

        DB::transaction(function () use ($rule, $payload, $recipientTypes) {
            $rule->update([
                'name'        => $payload['name'],
                'event'       => $payload['event'],
                'channel'     => 'database', // F-18b: database-only
                'is_active'   => $payload['is_active'],
                'description' => $payload['description'],
                // created_by + times_fired + created_at are intentionally
                // NOT updated — preserve attribution + history.
            ]);

            // Re-sync the pivot (delete old recipient types, insert new).
            $this->syncRecipientTypes($rule->id, $recipientTypes, $payload['recipient_user_id'], $replace = true);
        });

        return redirect()->route('admin.notifications.rules')
            ->with('success', "Rule '{$payload['name']}' updated.");
    }

    /**
     * Sync the multi-select recipient types to the pivot (F-18b).
     *
     * WORKFLOWS-AUDIT-2 (G-184): factored out of storeRule so updateRule
     * can re-use the same insert logic. When $replace=true, deletes the
     * existing pivot rows first (update path); when false, the table is
     * empty for a fresh rule so no delete is needed (store path).
     */
    private function syncRecipientTypes(int $ruleId, array $recipientTypes, ?int $recipientUserId = null, bool $replace = false): void
    {
        if ($replace) {
            DB::table('notification_rule_recipients')
                ->where('notification_rule_id', $ruleId)
                ->delete();
        }

        $now = now();
        $rows = [];
        foreach ($recipientTypes as $type) {
            $rows[] = [
                'notification_rule_id' => $ruleId,
                'recipient_type'       => $type,
                'recipient_user_id'    => ($type === 'specific_user') ? $recipientUserId : null,
                'created_at'           => $now,
                'updated_at'           => $now,
            ];
        }
        DB::table('notification_rule_recipients')->insert($rows);
    }

    /**
     * Toggle a rule's active status.
     */
    public function toggleRule(int $id)
    {
        $rule = NotificationRule::findOrFail($id);
        $rule->update(['is_active' => !$rule->is_active]);

        return redirect()->route('admin.notifications.rules')
            ->with('success', "Rule '{$rule->name}' " . ($rule->is_active ? 'activated' : 'deactivated') . ".");
    }

    /**
     * Delete a rule (pivot rows cascade via FK).
     */
    public function destroyRule(int $id)
    {
        $rule = NotificationRule::findOrFail($id);
        $rule->delete();

        return redirect()->route('admin.notifications.rules')
            ->with('success', "Rule '{$rule->name}' deleted.");
    }

    /**
     * F-18d — Reset all notification rules to the default set.
     *
     * Hard-deletes every existing notification_rules row (bypasses
     * SoftDeletes via the query builder), which cascades to the
     * notification_rule_recipients pivot via the FK, then re-runs the
     * NotificationRuleSeeder for a clean default set. Custom rules
     * created by admins are lost — the SweetAlert2 confirm on the
     * button makes this explicit.
     */
    public function resetDefaults()
    {
        DB::transaction(function () {
            // Hard-delete every rule (bypasses SoftDeletes). The FK on
            // notification_rule_recipients has cascadeOnDelete, so every
            // pivot row is removed automatically.
            DB::table('notification_rules')->delete();

            // Re-seed the default rule set (idempotent — table is empty
            // here so every default is inserted).
            app(NotificationRuleSeeder::class)->run();
        });

        return redirect()->route('admin.notifications.rules')
            ->with('success', 'Notification rules reset to the default set.');
    }

    /**
     * Notification inbox (current user's notifications).
     */
    public function inbox(Request $request)
    {
        $user = auth()->user();
        $filter = $request->input('filter', 'all'); // all, unread, read

        $notifications = $user->notifications()
            ->when($filter === 'unread', fn($q) => $q->whereNull('read_at'))
            ->when($filter === 'read', fn($q) => $q->whereNotNull('read_at'))
            ->paginate(25);

        $unreadCount = $user->unreadNotifications()->count();

        return view('admin.notifications.inbox', [
            'title'         => 'Notification Inbox',
            'notifications' => $notifications,
            'unreadCount'   => $unreadCount,
            'filter'        => $filter,
        ]);
    }

    /**
     * Mark a notification as read.
     */
    public function markRead(string $id)
    {
        $notification = auth()->user()->notifications()->where('id', $id)->first();

        if ($notification) {
            $notification->markAsRead();
        }

        return back()->with('success', 'Notification marked as read.');
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllRead()
    {
        // G-249 (MEDIUM): single bulk UPDATE replaces the collection
        // `markAsRead()` (which iterates + issues one UPDATE per row). The
        // bulk query is atomic + idempotent — two concurrent "Mark all read"
        // tabs both set read_at = now() on the same rows with no corruption.
        $userId = auth()->id();
        DB::table('notifications')
            ->where('notifiable_type', 'App\\Models\\User')
            ->where('notifiable_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back()->with('success', 'All notifications marked as read.');
    }

    /**
     * Get unread count (AJAX for the header badge).
     */
    public function unreadCount()
    {
        return response()->json([
            'count' => auth()->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * Get recent notifications (AJAX for the header dropdown).
     */
    public function recent()
    {
        $notifications = auth()->user()->notifications()
            ->limit(5)
            ->get()
            ->map(fn($n) => [
                'id'             => $n->id,
                'title'          => $n->data['title'] ?? 'Notification',
                'body'           => $n->data['body'] ?? '',
                'icon'           => $n->data['icon'] ?? 'fa-bell',
                'color'          => $n->data['color'] ?? 'primary',
                'event'          => $n->data['event'] ?? '',
                'reference_type' => $n->data['reference_type'] ?? null,
                'reference_id'   => $n->data['reference_id'] ?? null,
                'read_at'        => $n->read_at,
                'created_at'     => $n->created_at?->diffForHumans(),
            ]);

        return response()->json([
            'notifications' => $notifications,
            'unread_count'  => auth()->user()->unreadNotifications()->count(),
        ]);
    }
}
