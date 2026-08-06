<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

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
     *
     * G-253 (MEDIUM-WAVE-2-B / notification-workflow.md §G15): the previous
     * `NotificationRule::RECIPIENTS[$this->recipient_type] ?? $this->recipient_type`
     * silently returned the raw `recipient_type` string when the pivot row
     * carried an unknown type (e.g. a stale `warehouse_manager_of_branch_old`
     * left over from a refactor). The admin rules UI then showed the raw
     * string with no signal that something was wrong. We now log a warning
     * + return a clearly-marked "Unknown recipient type: <type>" label so
     * the admin can see + report the stale row. Chose log+skip over throw
     * to avoid 500ing the admin rules page or blocking other valid rule
     * selections on the same page.
     */
    public function getLabelAttribute(): string
    {
        // G-253: explicit unknown-type handling (was silent `?? $this->recipient_type`).
        if (!array_key_exists($this->recipient_type, NotificationRule::RECIPIENTS)) {
            Log::warning('Unknown recipient type: ' . $this->recipient_type, [
                'notification_rule_recipient_id' => $this->id,
                'notification_rule_id'           => $this->notification_rule_id,
                'recipient_type'                 => $this->recipient_type,
            ]);
            return 'Unknown recipient type: ' . $this->recipient_type;
        }

        $label = NotificationRule::RECIPIENTS[$this->recipient_type];
        if ($this->recipient_type === 'specific_user' && $this->recipientUser) {
            return 'Specific: ' . $this->recipientUser->username;
        }
        return $label;
    }
}
