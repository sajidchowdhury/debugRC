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
     *
     * G-076/G-078/G-079 (CRITICAL, WORKFLOWS-NOTIFICATION): the worker-forward
     * path is now DISABLED — this map is emptied. The 4 entries that were
     * here (`rcerp_sales_invoice` → `sales_finalize`, `rcerp_sales_challan`
     * → `challan_create`, `rcerp_sales_return` → `return_created`,
     * `rcerp_customer_payment` → `payment_receive`) caused three bugs:
     *
     *   - G-076: DOUBLE DISPATCH. Each of the 4 events was dispatched BOTH
     *     by direct PHP (SalesInvoiceService, SalesChallanService,
     *     CustomerPaymentService, SalesReturnService — which pass full
     *     $context) AND by the worker-forward path here (which passes no
     *     $context). Admins got 2 notifications per action; `times_fired`
     *     was incremented twice.
     *   - G-078: WRONG EVENT FORWARDED on UPDATE. The DB trigger fired
     *     `rcerp_sales_return` on BOTH INSERT and UPDATE, but the static
     *     map always translated it to `return_created`. So
     *     `SalesReturnService::confirmReturn` (which UPDATEs sales_returns
     *     + dispatches `return_confirmed`) ALSO triggered a spurious
     *     `return_created` via the worker. Same for `reverseReturn`.
     *   - G-079: WORKER-FORWARDED EVENTS HAVE NO $context. The pg_notify
     *     payload only carries `table/action/id/branch_id/changes` — no
     *     `salesman_id`, no `created_by`. Context-aware recipient types
     *     (`warehouse_manager_of_branch`, `salesman_of_invoice`,
     *     `invoice_creator`) silently returned empty collections.
     *
     * Fix: rely solely on direct PHP dispatch (which carries full context
     * + fires the correct sub-event: created vs confirmed vs reversed).
     * The DB trigger → pg_notify → Redis → SSE path is UNAFFECTED — it
     * still powers real-time page refresh via `publishToRedis()`. Only the
     * rule-based notification dispatch is removed from the worker.
     *
     * The `forwardToNotificationService()` method + the worker's
     * `--no-dispatch` flag are retained for backward compatibility, but
     * `forwardToNotificationService()` now early-returns (the
     * `if (!$eventName) return;` guard fires for every channel).
     */
    private const CHANNEL_EVENT_MAP = [];

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

        // G8 (G-212, REALTIME-3): Redis TTL + trim sizes read from
        // config/realtime.php (env-overridable). Previously hardcoded 600 / 500 / 200.
        $redisTtl = (int) config('realtime.listen_notify.redis_ttl', 600);
        $globalTrim = (int) config('realtime.listen_notify.global_trim', 500);
        $branchTrim = (int) config('realtime.listen_notify.branch_trim', 200);

        // --- Redis List delivery (for SSE polling) ---
        // LPUSH to global queue (all SSE clients poll this)
        try {
            $redis = Redis::connection('default');
            $redis->lpush(self::REDIS_PREFIX . 'global', $message);
            $redis->expire(self::REDIS_PREFIX . 'global', $redisTtl);
            // Trim to prevent unbounded growth
            $redis->ltrim(self::REDIS_PREFIX . 'global', 0, $globalTrim - 1);
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
                $redis->expire($branchKey, $redisTtl);
                $redis->ltrim($branchKey, 0, $branchTrim - 1);
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
     * Publish a notification payload to a specific user's Redis queue.
     *
     * G-008 (CRITICAL, architecture/realtime-events.md G1): the per-user
     * Redis queue `rcerp:sse:user:{user_id}` was polled by `SseController`
     * (RPOP L253) but NEVER written — `publishToRedis()` only writes to the
     * global + branch queues, and `NotificationService::dispatch` only emits
     * a global `rcerp_notification_dispatched` pg_notify. The result: every
     * per-user SSE poll returned an empty list, and a notification meant for
     * User A in Branch 1 was delivered to ALL users in Branch 1 (no per-user
     * targeting at the SSE layer).
     *
     * Fix: `NotificationService::dispatch` now calls this method once per
     * resolved recipient (inside the `foreach ($recipients as $user)` loop),
     * LPUSHing the same JSON envelope shape that `publishToRedis` uses, so
     * the recipient's SSE connection picks it up via RPOP. The global +
     * branch writes from `publishToRedis` are unchanged (they still fire via
     * the `emitNotify('rcerp_notification_dispatched', …)` path).
     *
     * @param int    $userId    The recipient user ID (matches SseController's `$userQueueKey`)
     * @param string $pgChannel The PostgreSQL channel name (e.g. rcerp_notification_dispatched)
     * @param array  $payload   The decoded JSON payload
     */
    public function publishToUser(int $userId, string $pgChannel, array $payload): void
    {
        $message = json_encode([
            'channel' => $pgChannel,
            'payload' => $payload,
            'published_at' => now()->toISOString(),
        ], JSON_UNESCAPED_UNICODE);

        // G8 (G-212, REALTIME-3): Redis TTL + trim read from config (was
        // hardcoded 600 / 199). Matches the publishToRedis discipline.
        $redisTtl = (int) config('realtime.listen_notify.redis_ttl', 600);
        $userTrim = (int) config('realtime.listen_notify.user_trim', 200);

        try {
            $redis = Redis::connection('default');
            $userKey = self::REDIS_PREFIX . "user:{$userId}";
            $redis->lpush($userKey, $message);
            $redis->expire($userKey, $redisTtl);
            // Trim to prevent unbounded growth.
            $redis->ltrim($userKey, 0, $userTrim - 1);
        } catch (\Throwable $e) {
            Log::warning('LISTEN/NOTIFY: Redis LPUSH to per-user queue failed', [
                'channel' => $pgChannel,
                'user_id' => $userId,
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
            // G-076/G-078/G-079: CHANNEL_EVENT_MAP is intentionally empty —
            // the worker-forward path is disabled. Direct PHP dispatch
            // (SalesInvoiceService, SalesChallanService, CustomerPaymentService,
            // SalesReturnService) handles rule-based notification with full
            // $context. This early-return fires for every channel now.
            return;
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
                SELECT channel, COUNT(*) AS listener_count, MIN(pid) AS pid
                FROM pg_listening_channels()
                WHERE channel LIKE 'rcerp_%'
                GROUP BY channel
                ORDER BY channel
            ");
            return collect($results)->map(fn($r) => [
                'channel' => $r->channel,
                'pid' => $r->pid,
                'listener_count' => $r->listener_count,
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
