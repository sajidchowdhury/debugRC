<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * ERP Notification — Phase 10 (F-18b: database-only).
 *
 * The single notification class used by all ERP events. Dispatched by
 * NotificationService based on active notification_rules.
 *
 * F-18b cleanup: the vestigial `broadcast` channel + `toBroadcast()`
 * method have been removed. No `config/broadcasting.php` exists in the
 * app (no Reverb / Pusher / Echo installed), so the broadcast channel
 * silently no-op'd. Real-time push to the browser is handled by the SSE
 * pipeline (PostgreSQL LISTEN/NOTIFY → Redis → EventSource) which is
 * fired separately by NotificationService via ListenNotifyService — NOT
 * by Laravel's broadcast system. The `channels` constructor parameter is
 * retained for backward-compatibility of the call site but is ignored;
 * via() always returns the database channel only.
 */
class ERPNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $body,
        public string $event,
        public ?string $referenceType = null,
        public ?int $referenceId = null,
        public string $icon = 'fa-bell',
        public string $color = 'primary',
        public array $channels = ['database'],
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * F-18b: always database-only. The `channels` constructor argument is
     * accepted for backward compatibility but the broadcast channel is no
     * longer wired (no broadcasting config in the app).
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation for database storage.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title'          => $this->title,
            'body'           => $this->body,
            'event'          => $this->event,
            'reference_type' => $this->referenceType,
            'reference_id'   => $this->referenceId,
            'icon'           => $this->icon,
            'color'          => $this->color,
        ];
    }
}
