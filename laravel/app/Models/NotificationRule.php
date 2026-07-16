<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Notification Rule — Phase 10.
 *
 * Admin-configurable rule that defines when a notification fires and who receives it.
 *
 * Events (trigger when):
 *   sales_finalize, challan_create, godown_create, payment_receive,
 *   soft_delete, accounts_entry, user_login
 *
 * Recipient types:
 *   admin, superadmin, sales_manager, accountant, all_users, specific_user
 *
 * Channels:
 *   database (stored in notifications table), broadcast (Reverb WebSocket — live)
 *
 * @property int $id
 * @property string $name
 * @property string $event
 * @property string $recipient_type
 * @property int|null $recipient_user_id
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
        'name', 'event', 'recipient_type', 'recipient_user_id',
        'channel', 'is_active', 'description', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'times_fired' => 'integer',
        'recipient_user_id' => 'integer',
        'created_by' => 'integer',
    ];

    /**
     * All available events (trigger points).
     */
    public const EVENTS = [
        'sales_finalize' => 'Sales Invoice Finalized',
        'challan_create' => 'Challan Created',
        'godown_create' => 'Godown Copy Created',
        'payment_receive' => 'Payment Received from Client',
        'soft_delete' => 'Any Record Soft-Deleted',
        'accounts_entry' => 'Any Accounting Entry Posted',
        'user_login' => 'User Login',
    ];

    /**
     * All available recipient types.
     */
    public const RECIPIENTS = [
        'admin' => 'Admin',
        'superadmin' => 'Super Admin',
        'sales_manager' => 'Sales Manager',
        'accountant' => 'Accounts',
        'all_users' => 'All Users',
        'specific_user' => 'Specific User',
    ];

    /**
     * Available channels.
     */
    public const CHANNELS = [
        'database' => 'Database (In-App)',
        'broadcast' => 'Broadcast (Live WebSocket)',
        'both' => 'Both (Database + Live)',
    ];

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipientUser(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
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
     * Get the recipient label.
     */
    public function getRecipientLabelAttribute(): string
    {
        if ($this->recipient_type === 'specific_user' && $this->recipientUser) {
            return 'Specific: ' . $this->recipientUser->username;
        }
        return self::RECIPIENTS[$this->recipient_type] ?? $this->recipient_type;
    }
}
