<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * ERP Notification — Phase 10.
 *
 * The single notification class used by all ERP events. Dispatched by
 * NotificationService based on active notification_rules.
 *
 * Supports both database (in-app) and broadcast (Reverb WebSocket — live)
 * channels. The rule's channel setting determines which channels are used.
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
     */
    public function via(object $notifiable): array
    {
        $via = [];
        if (in_array('database', $this->channels) || in_array('both', $this->channels)) {
            $via[] = 'database';
        }
        if (in_array('broadcast', $this->channels) || in_array('both', $this->channels)) {
            $via[] = 'broadcast';
        }
        if (empty($via)) {
            $via[] = 'database';
        }
        return $via;
    }

    /**
     * Get the array representation for database storage.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'event' => $this->event,
            'reference_type' => $this->referenceType,
            'reference_id' => $this->referenceId,
            'icon' => $this->icon,
            'color' => $this->color,
        ];
    }

    /**
     * Get the broadcast representation for Reverb WebSocket (live alerts).
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'title' => $this->title,
            'body' => $this->body,
            'event' => $this->event,
            'reference_type' => $this->referenceType,
            'reference_id' => $this->referenceId,
            'icon' => $this->icon,
            'color' => $this->color,
            'created_at' => now()->toISOString(),
        ]);
    }
}
