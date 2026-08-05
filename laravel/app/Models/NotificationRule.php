<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Notification Rule — Phase 10 (F-18b: multi-select recipients).
 *
 * Admin-configurable rule that defines when a notification fires and who
 * receives it. As of F-18b a rule may target MULTIPLE recipient types
 * (stored in the notification_rule_recipients pivot) — e.g. "After Sales
 * Confirm → [Admin, Warehouse Manager of branch, Salesman of invoice]".
 *
 * Events (trigger when) — see EVENTS constant. Recipient types — see
 * RECIPIENTS constant (some are context-aware, resolved at dispatch time
 * using the $context array passed to NotificationService::dispatch()).
 *
 * @property int $id
 * @property string $name
 * @property string $event
 * @property string $channel
 * @property bool $is_active
 * @property int $times_fired
 * @property string|null $description
 * @property int|null $created_by
 */
class NotificationRule extends Model
{
    use SoftDeletes;

    protected $table = 'notification_rules';

    protected $fillable = [
        'name', 'event',
        'channel', 'is_active', 'description', 'created_by',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'times_fired' => 'integer',
        'created_by'  => 'integer',
    ];

    /**
     * All available events (trigger points).
     *
     * F-18b: expanded to cover the user's 9 predefined business events.
     * The first 10 keys (sales_finalize … customer_limit_increased) are
     * the canonical business events; the trailing return_confirmed /
     * return_reversed are sub-flows of "After Sales return" that are
     * already dispatched by SalesReturnService and worth configuring
     * separately. soft_delete / accounts_entry / godown_create are kept
     * as additional infrastructure events (already in the original
     * Phase-10 set) — admins may configure them but they are not in the
     * user's 9.
     */
    public const EVENTS = [
        // — User's 9 predefined business events —
        'sales_finalize'            => 'After Sales Confirm',
        'challan_create'            => 'After Create Challan Copy',
        'user_login'                => 'After Login',
        'user_logout'               => 'After Logout',
        'damage_invoice_created'    => 'After Create Damage Invoice',
        'payment_receive'           => 'After Receive Money',
        'return_created'            => 'After Sales Return',
        'branch_demand_created'     => 'After Branch Demand',
        'customer_limit_increased'  => 'After Increasing Customer Limit',
        // — Additional infrastructure events (pre-existing) —
        'godown_create'             => 'Godown Copy Created',
        'soft_delete'               => 'Record Soft-Deleted',
        'accounts_entry'            => 'Accounting Entry Posted',
        // — Sales-return sub-flows (already dispatched; keep configurable) —
        'return_confirmed'          => 'Sales Return Confirmed',
        'return_reversed'           => 'Sales Return Reversed',
        // — Phase 5 approval workflow events (G-080, WORKFLOWS-APPROVAL) —
        // Dispatched by ApprovalService::notifyApprovers/notifyRequester.
        // Previously these 4 events were dispatched but NOT in EVENTS, so
        // NotificationService::dispatch silently returned 0 (no rule matched)
        // and approvers/requesters never received notifications. Now
        // configurable + seeded with default rules (see NotificationRuleSeeder).
        'approval_request_submitted'    => 'Approval Request Submitted',
        'approval_request_next_level'   => 'Approval Request Advanced to Next Level',
        'approval_request_approved'     => 'Approval Request Approved',
        'approval_request_rejected'     => 'Approval Request Rejected',
    ];

    /**
     * All available recipient types.
     *
     * F-18b adds 3 context-aware types (resolved at dispatch time using
     * the $context array): warehouse_manager_of_branch, salesman_of_invoice,
     * invoice_creator. The legacy global types are retained; sales_manager
     * is un-fused to mean ONLY manager+salesman roles (was previously
     * over-broad to include admin/superadmin).
     */
    public const RECIPIENTS = [
        'all_users'                  => 'All Users',
        'admin'                      => 'Only Admin',
        'superadmin'                 => 'Super Admin',
        'sales_manager'              => 'Sales Manager',
        'accountant'                 => 'Accountant',
        'warehouse_manager'          => 'Warehouse Manager (all branches)',
        // — Context-aware (require $context at dispatch time) —
        'warehouse_manager_of_branch' => 'Warehouse Manager of event branch',
        'salesman_of_invoice'         => 'Salesman of the invoice',
        'invoice_creator'             => 'Creator of the record',
        // — Explicit —
        'specific_user'              => 'Specific User',
    ];

    /**
     * Available channels.
     *
     * F-18b: collapsed to database-only. The `broadcast`/`both` options
     * were vestigial — no config/broadcasting.php exists in the app and
     * ERPNotification no longer ships a broadcast channel. Real-time push
     * to the browser is handled by the SSE pipeline (PostgreSQL
     * LISTEN/NOTIFY → Redis → EventSource), NOT Laravel broadcasting.
     */
    public const CHANNELS = [
        'database' => 'Database (In-App)',
    ];

    /**
     * Recipient types that depend on event context (the $context array
     * passed to NotificationService::dispatch()). Used by the UI to hint
     * which selections need contextual data and by resolveRecipients().
     */
    public const CONTEXT_AWARE_RECIPIENTS = [
        'warehouse_manager_of_branch',
        'salesman_of_invoice',
        'invoice_creator',
    ];

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The multi-select recipient-type selections for this rule (F-18b).
     */
    public function recipientTypes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(NotificationRuleRecipient::class, 'notification_rule_id')
                    ->orderBy('id');
    }

    /**
     * Scope: active rules only.
     */
    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: rules for a specific event.
     */
    public function scopeForEvent(\Illuminate\Database\Eloquent\Builder $query, string $event): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('event', $event);
    }

    /**
     * Get the event label.
     */
    public function getEventLabelAttribute(): string
    {
        return self::EVENTS[$this->event] ?? $this->event;
    }

    /**
     * Get the recipient label(s). With multi-select (F-18b) this joins
     * every recipient-type selection with a comma.
     */
    public function getRecipientLabelAttribute(): string
    {
        $types = $this->recipientTypes;
        if ($types->isEmpty()) {
            return '—';
        }
        return $types->map(fn ($r) => $r->label)->implode(', ');
    }
}
