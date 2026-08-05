<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Store Notification Rule Request — Phase 10 (F-18b: multi-select recipients).
 *
 * WORKFLOWS-AUDIT-2 (G-184): promotes the inline `$request->validate([…])`
 * that used to live in `NotificationController::storeRule()` into a typed
 * Form Request. Mirrors the pattern established by the sibling accounting
 * FormRequests (StoreMoneyTransferRequest, StoreManualJournalRequest, …)
 * and the WORKFLOWS-AUDIT-1 approval FormRequests.
 *
 * Validation rules mirror the inline block at NotificationController.php
 * L66-76 + the `notification_rules` / `notification_rule_recipients` schema:
 *   - name:  required|string|max:100 (column is varchar(100))
 *   - event: required|in:<NotificationRule::EVENTS keys> (canonical event list)
 *   - recipient_types: required|array|min:1 — multi-select (F-18b). Each entry
 *     must be a key of NotificationRule::RECIPIENTS.
 *   - recipient_user_id: nullable|integer|exists:users,id — required IFF
 *     'specific_user' is among the selections (checked in the controller so
 *     the error message can reference the recipient_type context).
 *   - description: nullable|string|max:500
 *   - is_active: boolean
 *   - channel: sometimes|string|in:<NotificationRule::CHANNELS keys> — kept
 *     for backward-compat; the controller forces 'database' (F-18b collapsed
 *     the channel list to database-only).
 *
 * RBAC is enforced by the route middleware (`role:admin` on the
 * `admin/notifications/rules` group) + the `view-notification-rules` Gate
 * (AppServiceProvider::define). authorize() returns true so the FormRequest
 * does not double-gate.
 */
class StoreNotificationRuleRequest extends FormRequest
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
            // channel kept for backward-compat but forced to 'database' (F-18b).
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

    /**
     * Build the validated payload used by NotificationController::storeRule().
     * De-duplicates the recipient_types array (defensive — the UI sends unique
     * values, but a forged request could send duplicates) and forces channel
     * to 'database' (F-18b: database-only).
     */
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
            'channel'           => 'database', // F-18b: database-only (broadcast removed)
        ];
    }
}
