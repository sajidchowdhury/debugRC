<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Notification Rule Recipient — Phase 4 / F-18b.
 *
 * Pivot row representing ONE recipient-type selection for a notification
 * rule. A rule has many of these (multi-select per the user's redefined
 * F-18 spec — e.g. "After Sales Confirm → [Admin, Warehouse Manager of
 * branch, Salesman of invoice]").
 *
 * `recipient_type` is a key from NotificationRule::RECIPIENTS (a string,
 * NOT a foreign key to a recipients table — hence this is a hasMany pivot
 * rather than a belongsToMany).
 *
 * `recipient_user_id` is only populated when recipient_type is
 * `specific_user`.
 *
 * @property int $id
 * @property int $notification_rule_id
 * @property string $recipient_type
 * @property int|null $recipient_user_id
 */
class NotificationRuleRecipient extends Model
{
    protected $table = 'notification_rule_recipients';

    protected $fillable = [
        'notification_rule_id',
        'recipient_type',
        'recipient_user_id',
    ];

    protected $casts = [
        'notification_rule_id' => 'integer',
        'recipient_user_id'    => 'integer',
    ];

    public $timestamps = true;

    public function rule(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(NotificationRule::class, 'notification_rule_id');
    }

    public function recipientUser(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    /**
     * Human-readable label for this recipient selection.
     */
    public function getLabelAttribute(): string
    {
        $label = NotificationRule::RECIPIENTS[$this->recipient_type] ?? $this->recipient_type;
        if ($this->recipient_type === 'specific_user' && $this->recipientUser) {
            return 'Specific: ' . $this->recipientUser->username;
        }
        return $label;
    }
}
