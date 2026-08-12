<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update Notification Rule Request — Phase 10 (F-18b: multi-select recipients).
 *
 * WORKFLOWS-AUDIT-2 (G-184): the sibling of StoreNotificationRuleRequest —
 * validates PUT/PATCH `/admin/notifications/rules/{id}`. Previously no
 * `updateRule` route existed at all (rules could only be created /
 * toggle-activated / deleted — never edited), forcing admins to delete +
 * recreate a rule to change its name/event/recipients/description, losing
 * `times_fired` history + `created_at` + `created_by`.
 *
 * Validation rules mirror StoreNotificationRuleRequest — the update is a
 * FULL replacement of the rule's editable fields (name, event, recipient
 * multi-select, description, is_active). `times_fired`, `created_at`,
 * `created_by` are preserved (NOT in $fillable-for-update; the controller
 * syncs the pivot via delete+insert, not via a mass update).
 *
 * RBAC: route middleware `role:admin` + Gate `view-notification-rules`.
 */
class UpdateNotificationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // RBAC: route middleware `role:admin` + Gate `view-notification-rules`.
        return true;
    }

    public function rules(): array
    {
        return [
            'name'              => ['required', 'string', 'max:100'],
            'event'             => ['required', 'string', Rule::in(array_keys(\App\Models\NotificationRule::EVENTS))],
            'recipient_types'   => ['required', 'array', 'min:1'],
            'recipient_types.*' => ['required', 'string', Rule::in(array_keys(\App\Models\NotificationRule::RECIPIENTS))],
            'recipient_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'description'       => ['nullable', 'string', 'max:500'],
            'is_active'         => ['boolean'],
            'channel'           => ['sometimes', 'string', Rule::in(array_keys(\App\Models\NotificationRule::CHANNELS))],
        ];
    }

    public function attributes(): array
    {
        return [
            'name'              => 'rule name',
            'event'             => 'event',
            'recipient_types'   => 'recipient types',
            'recipient_types.*' => 'recipient type',
            'recipient_user_id' => 'specific user',
            'description'       => 'description',
            'is_active'         => 'active status',
            'channel'           => 'channel',
        ];
    }

    public function toServicePayload(): array
    {
        $validated = $this->validated();

        $recipientTypes = array_values(array_unique($validated['recipient_types']));

        return [
            'name'              => $validated['name'],
            'event'             => $validated['event'],
            'recipient_types'   => $recipientTypes,
            'recipient_user_id' => $validated['recipient_user_id'] ?? null,
            'description'       => $validated['description'] ?? null,
            'is_active'         => $validated['is_active'] ?? true,
            'channel'           => 'database', // F-18b: database-only
        ];
    }
}
