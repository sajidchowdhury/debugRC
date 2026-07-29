<?php

namespace App\Services\Notification;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Listen/Notify Service — Phase 1E (Task 31).
 *
 * Bridges PostgreSQL LISTEN/NOTIFY with Redis Pub/Sub and the
 * Laravel notification system. Provides two modes of operation:
 *
 * 1. **Worker Mode** (ListenNotifyWorker artisan command):
 *    - Opens a dedicated PostgreSQL connection
 *    - LISTENs on all rcerp_* channels
 *    - On notification: publishes to Redis Pub/Sub + optionally
 *      dispatches via NotificationService
 *
 * 2. **SSE Mode** (SseController):
 *    - Subscribes to Redis Pub/Sub
 *    - Streams events to browser via Server-Sent Events
 *
 * Architecture:
 *   DB Trigger → pg_notify(channel, payload)
 *     → PHP Worker (LISTEN) → Redis Pub/Sub
 *       → SSE Controller → Browser (EventSource)
 *     → NotificationService (rule-based dispatch)
 *
 * Redis Pub/Sub channels:
 *   - rcerp:sse:{user_id}     — per-user SSE stream
 *   - rcerp:sse:branch:{id}   — per-branch SSE stream
 *   - rcerp:sse:global        — all authenticated users
 *
 * The pg_notify payload is JSON:
 *   {
 *     "table": "sales_invoices",
 *     "action": "INSERT",
 *     "id": 42,
 *     "branch_id": 1,
 *     "changes": {"status": "finalized"},
 *     "triggered_at": "2025-07-20T10:30:00+06:00"
 *   }
 */
class ListenNotifyService
{
    /**
     * PostgreSQL channels to LISTEN on.
     * These match the pg_notify() calls in the DB triggers.
     */
    public const PG_CHANNELS = [
        'rcerp_sales_invoice',
        'rcerp_sales_challan',
        'rcerp_sales_return',
        'rcerp_customer_payment',
        'rcerp_stock_change',
        'rcerp_journal_entry',
        'rcerp_system',
        'rcerp_notification_dispatched',
        // Phase 2 (Damage plan) — closes the real-time parity gap: every
        // other transactional table had a NOTIFY trigger except damage_invoices.
        // The worker now LISTENs so damage index/detail pages auto-refresh
        // via SSE when another user creates/confirms/cancels a damage.
        'rcerp_damage_change',
        // Phase 3 (Damage plan) — evidence attachment changes. Fires on
        // INSERT/DELETE of damage_attachments (not on the parent header), so
        // the damage detail page can auto-refresh its Evidence gallery when
        // another tab uploads a photo. Deliberately separate from
        // rcerp_damage_change so the index refresh banner does NOT fire on
        // an attachment upload (the header row is unchanged).
        'rcerp_damage_attachment_change',
    ];

    /**
     * Redis Pub/Sub channel prefixes.
     */
    public const REDIS_PREFIX = 'rcerp:sse:';

    /**
     * Map PG channel → Laravel notification event name.
     * Used when forwarding DB events to the rule-based notification system.
     */
    private const CHANNEL_EVENT_MAP = [
        'rcerp_sales_invoice'   => 'sales_finalize',
        'rcerp_sales_challan'   => 'challan_create',
        'rcerp_sales_return'    => 'return_created',
        'rcerp_customer_payment'=> 'payment_receive',
        'rcerp_system'          => 'system_policy_change',
    ];

    /**
     * Publish a notification payload to Redis.
     *
     * Called by the ListenNotifyWorker when a pg_notify event is received.
     * Uses dual delivery:
     *   1. Redis Pub/Sub — for any external subscribers (monitoring, logging)
     *   2. Redis Lists (LPUSH) — for SSE controller polling (PHP-FPM compatible)
     *
     * Redis List keys:
     *   - rcerp:sse:global             — all SSE clients poll this
     *   - rcerp:sse:branch:{branch_id} — branch-specific SSE clients
     *
     * @param string $pgChannel The PostgreSQL channel name (e.g., rcerp_sales_invoice)
     * @param array  $payload   The decoded JSON payload from pg_notify
     */
    public function publishToRedis(string $pgChannel, array $payload): void
    {
        $message = json_encode([
            'channel' => $pgChannel,
            'payload' => $payload,
            'published_at' => now()->toISOString(),
        ], JSON_UNESCAPED_UNICODE);

        // --- Redis List delivery (for SSE polling) ---
        // LPUSH to global queue (all SSE clients poll this)
        try {
            $redis = Redis::connection('default');
            $redis->lpush(self::REDIS_PREFIX . 'global', $message);
            $redis->expire(self::REDIS_PREFIX . 'global', 600); // TTL 10 min
            // Trim to prevent unbounded growth (keep last 500 events)
            $redis->ltrim(self::REDIS_PREFIX . 'global', 0, 499);
        } catch (\Throwable $e) {
            Log::warning('LISTEN/NOTIFY: Redis LPUSH to global queue failed', [
                'channel' => $pgChannel,
                'error' => $e->getMessage(),
            ]);
        }

        // LPUSH to branch-specific queue
        $branchId = $payload['branch_id'] ?? null;
        if ($branchId) {
            try {
                $branchKey = self::REDIS_PREFIX . "branch:{$branchId}";
                $redis = Redis::connection('default');
                $redis->lpush($branchKey, $message);
                $redis->expire($branchKey, 600);
                $redis->ltrim($branchKey, 0, 199);
            } catch (\Throwable $e) {
                Log::warning('LISTEN/NOTIFY: Redis LPUSH to branch queue failed', [
                    'channel' => $pgChannel,
                    'branch_id' => $branchId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // --- Redis Pub/Sub delivery (for external subscribers) ---
        // This is fire-and-forget; if no subscriber, message is simply not delivered
        try {
            Redis::connection('default')->publish(self::REDIS_PREFIX . 'pubsub:global', $message);
        } catch (\Throwable $e) {
            Log::warning('LISTEN/NOTIFY: Redis Pub/Sub publish failed', [
                'channel' => $pgChannel,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Forward a pg_notify event to Laravel's NotificationService
     * for rule-based dispatch (database + broadcast channels).
     *
     * Only forwards events that have a corresponding notification rule mapping.
     * The NotificationService handles the actual rule lookup + user resolution.
     *
     * @param string $pgChannel The PostgreSQL channel name
     * @param array  $payload   The decoded JSON payload
     */
    public function forwardToNotificationService(
        string $pgChannel,
        array $payload,
        NotificationService $notificationService
    ): void {
        $eventName = self::CHANNEL_EVENT_MAP[$pgChannel] ?? null;

        if (!$eventName) {
            return; // No mapping — skip notification dispatch
        }

        // Build a human-readable body from the payload
        $body = $this->buildNotificationBody($pgChannel, $payload);
        $referenceType = $payload['table'] ?? null;
        $referenceId = $payload['id'] ?? null;

        try {
            $notificationService->dispatch(
                event: $eventName,
                body: $body,
                referenceType: $referenceType,
                referenceId: $referenceId,
                extra: [
                    'pg_channel' => $pgChannel,
                    'changes' => $payload['changes'] ?? [],
                ]
            );
        } catch (\Throwable $e) {
            Log::error('LISTEN/NOTIFY: Failed to forward to NotificationService', [
                'channel' => $pgChannel,
                'event' => $eventName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Emit a pg_notify from PHP (application-level NOTIFY).
     *
     * Useful for events that don't have DB triggers (e.g., user login,
     * API calls) but still need real-time notification.
     *
     * @param string $channel  The PG channel name
     * @param array  $data     The payload data
     */
    public function emitNotify(string $channel, array $data): void
    {
        $payload = json_encode(array_merge($data, [
            'triggered_at' => now()->toISOString(),
        ]), JSON_UNESCAPED_UNICODE);

        try {
            DB::statement("SELECT pg_notify(?, ?)", [$channel, $payload]);
        } catch (\Throwable $e) {
            Log::warning('LISTEN/NOTIFY: pg_notify failed', [
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if the PostgreSQL connection supports LISTEN/NOTIFY.
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        try {
            $result = DB::selectOne("SELECT pg_is_in_recovery()");
            // LISTEN/NOTIFY only works on the primary (read-write) server
            return !$result->pg_is_in_recovery;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get the list of active PG channels that have listeners.
     *
     * @return array
     */
    public function getActiveChannels(): array
    {
        try {
            $results = DB::select("
                SELECT channel, pid, listen_count
                FROM (
                    SELECT channel, pid,
                           COUNT(*) OVER (PARTITION BY channel) AS listen_count
                    FROM pg_listening_channels()
                ) sub
                WHERE channel LIKE 'rcerp_%'
                GROUP BY channel, pid, listen_count
                ORDER BY channel
            ");
            return collect($results)->map(fn($r) => [
                'channel' => $r->channel,
                'pid' => $r->pid,
                'listener_count' => $r->listen_count,
            ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Build a human-readable notification body from the payload.
     *
     * @param string $pgChannel
     * @param array  $payload
     * @return string
     */
    private function buildNotificationBody(string $pgChannel, array $payload): string
    {
        $table = $payload['table'] ?? 'record';
        $action = $payload['action'] ?? 'changed';
        $id = $payload['id'] ?? '?';
        $changes = $payload['changes'] ?? [];

        $actionLabel = match (strtolower($action)) {
            'insert' => 'created',
            'update' => 'updated',
            default  => $action,
        };

        $changeDescription = '';
        if (!empty($changes)) {
            $keys = array_keys($changes);
            $changeDescription = ' (' . implode(', ', array_map(fn($k) => "{$k}: " . json_encode($changes[$k]), $keys)) . ')';
        }

        return ucfirst(str_replace('_', ' ', $table)) . " #{$id} {$actionLabel}{$changeDescription}";
    }
}
