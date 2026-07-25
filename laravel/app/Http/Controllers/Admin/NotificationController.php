<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationRule;
use App\Models\NotificationRuleRecipient;
use App\Models\User;
use App\Services\Notification\NotificationService;
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
     */
    public function storeRule(Request $request)
    {
        $validated = $request->validate([
            'name'              => 'required|string|max:100',
            'event'             => 'required|string|in:' . implode(',', array_keys(NotificationRule::EVENTS)),
            'recipient_types'   => 'required|array|min:1',
            'recipient_types.*' => 'required|string|in:' . implode(',', array_keys(NotificationRule::RECIPIENTS)),
            'recipient_user_id' => 'nullable|integer|exists:users,id',
            'description'       => 'nullable|string|max:500',
            'is_active'         => 'boolean',
            // channel kept for backward-compat but forced to 'database' (F-18b).
            'channel'           => 'sometimes|string|in:' . implode(',', array_keys(NotificationRule::CHANNELS)),
        ]);

        $recipientTypes = array_values(array_unique($validated['recipient_types']));

        // If specific_user is among the selections, a recipient_user_id is required.
        if (in_array('specific_user', $recipientTypes, true) && empty($validated['recipient_user_id'])) {
            return back()->withInput()->with('error', 'Specific User recipient requires a user selection.');
        }

        DB::transaction(function () use ($validated, $recipientTypes, &$rule) {
            $rule = NotificationRule::create([
                'name'        => $validated['name'],
                'event'       => $validated['event'],
                'channel'     => 'database', // F-18b: database-only (broadcast removed)
                'is_active'   => $validated['is_active'] ?? true,
                'description' => $validated['description'] ?? null,
                'created_by'  => auth()->id(),
            ]);

            // Sync the multi-select recipient types to the pivot (F-18b).
            $now = now();
            $rows = [];
            foreach ($recipientTypes as $type) {
                $rows[] = [
                    'notification_rule_id' => $rule->id,
                    'recipient_type'       => $type,
                    'recipient_user_id'    => ($type === 'specific_user') ? ($validated['recipient_user_id'] ?? null) : null,
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ];
            }
            DB::table('notification_rule_recipients')->insert($rows);
        });

        return redirect()->route('admin.notifications.rules')
            ->with('success', "Rule '{$validated['name']}' created.");
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
        auth()->user()->unreadNotifications->markAsRead();

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
