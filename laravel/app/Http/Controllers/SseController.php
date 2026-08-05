<?php

namespace App\Http\Controllers;

use App\Services\Notification\ListenNotifyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * SSE (Server-Sent Events) Controller — Phase 1E (Task 31).
 *
 * Provides real-time event streaming to the browser via SSE.
 * The browser connects via `new EventSource('/sse/events')` and
 * receives pushed events from PostgreSQL LISTEN/NOTIFY (bridged
 * through Redis Pub/Sub by the ListenNotifyWorker).
 *
 * Architecture:
 *   DB Trigger → pg_notify() → ListenNotifyWorker → Redis Pub/Sub
 *     → SseController (poll Redis list) → Browser (EventSource)
 *
 * Endpoints:
 *   GET /sse/events       — SSE stream for authenticated user
 *   GET /sse/status       — JSON status of LISTEN/NOTIFY system
 *
 * Implementation note:
 *   Rather than using Redis Pub/Sub's blocking subscribe() (which
 *   conflicts with PHP-FPM's request lifecycle), the SSE endpoint
 *   polls a Redis List that the ListenNotifyWorker pushes to.
 *   The worker pushes events to per-user and per-branch lists,
 *   and this controller pops them in a non-blocking loop.
 *
 * Redis keys used:
 *   rcerp:sse:user:{user_id}    — per-user event queue (LPUSH by worker, RPOP by SSE)
 *   rcerp:sse:branch:{branch_id} — per-branch event queue
 *   rcerp:sse:global             — global event queue (all users)
 *
 * Heartbeat is sent every `SSE_HEARTBEAT_INTERVAL` seconds (default 30) to
 * keep the connection alive through proxies + load balancers. All tuning
 * constants live in `config/realtime.php` (REALTIME-3, G8/G-212) — the prior
 * `private const` declarations are gone.
 */
class SseController extends Controller
{
    /**
     * Maximum events to RPOP per queue per poll iteration.
     */
    private const POLL_BATCH_SIZE = 10;

    /**
     * SSE event stream for the authenticated user.
     *
     * Polls Redis List queues for events pushed by the ListenNotifyWorker
     * and streams them to the browser via SSE.
     */
    public function events(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            abort(401, 'Authentication required for SSE stream.');
        }

        $branchId = session('branch_id');
        $userId = $user->id;

        // G9 (G-213, REALTIME-3): the user's role is consulted by the explicit
        // branch filter below. Admins/superadmins see events with null
        // `branch_id` (system-wide events like journal entries, stock changes
        // resolved to a warehouse branch, etc.); non-admins are filtered OUT
        // of null-branch_id events to prevent cross-branch leakage via the
        // global queue. Previously null eventBranchId short-circuited the
        // filter to false → unfiltered → leak.
        $isAdmin = in_array($user->role->slug ?? null, ['admin', 'superadmin'], true);

        // Set headers for SSE
        $headers = [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no', // Disable Nginx buffering
        ];

        // Check if we can use streaming response
        if (!function_exists('stream_set_blocking')) {
            return response()->json(['error' => 'SSE not supported'], 501);
        }

        $startTime = time();
        $lastHeartbeat = $startTime;

        // Register this SSE client's user-specific queue
        $userQueueKey = "rcerp:sse:user:{$userId}";
        $branchQueueKey = $branchId ? "rcerp:sse:branch:{$branchId}" : null;
        $globalQueueKey = "rcerp:sse:global";

        Log::info('SSE: Client connected', [
            'user_id' => $userId,
            'branch_id' => $branchId,
        ]);

        $heartbeatInterval = (int) config('realtime.sse.heartbeat_interval', 30);
        $maxConnectionTime = (int) config('realtime.sse.max_connection_time', 300);
        $pollIntervalUs = (int) config('realtime.sse.poll_interval_us', 100000);

        // Use Laravel's streaming response
        $response = response()->stream(function () use (
            $userId, $branchId, $userQueueKey, $branchQueueKey,
            $globalQueueKey, $startTime, &$lastHeartbeat,
            $heartbeatInterval, $maxConnectionTime, $pollIntervalUs, $isAdmin
        ) {
            // Disable time limit for long-running SSE connection
            @set_time_limit(0);

            // Send initial connection event
            $this->sendSseEvent('connected', [
                'user_id' => $userId,
                'branch_id' => $branchId,
                'connected_at' => now()->toISOString(),
            ]);

            // Main polling loop
            while (true) {
                // Check connection time limit
                if (time() - $startTime >= $maxConnectionTime) {
                    $this->sendSseEvent('reconnect', [
                        'reason' => 'max_connection_time',
                        'retry_after_ms' => 1000,
                    ]);
                    break;
                }

                // Check if client disconnected (connection aborted)
                if (connection_aborted()) {
                    break;
                }

                // Poll Redis for events from all queues
                $events = $this->pollRedisQueues($userQueueKey, $branchQueueKey, $globalQueueKey);

                foreach ($events as $event) {
                    $pgChannel = $event['channel'] ?? 'unknown';
                    $payload = $event['payload'] ?? [];

                    // G9 (G-213, REALTIME-3): EXPLICIT branch isolation.
                    // Previously the filter was `if ($eventBranchId && $branchId
                    // && (int) $eventBranchId !== (int) $branchId) continue;` —
                    // a null $eventBranchId short-circuited the `&&` to false,
                    // so events with no branch_id were forwarded UNFILTERED to
                    // ALL clients via the global queue. Combined with G2
                    // (partition migration loses branch_id), this leaked
                    // sales_returns + damage_invoices events to every branch.
                    //
                    // New filter logic (defense-in-depth):
                    //   1. If the event HAS a branch_id and it differs from the
                    //      client's branch → skip (cross-branch leak).
                    //   2. If the event has NO branch_id (null) AND the client
                    //      is NOT admin → skip (null-branch leak). Admins see
                    //      system-wide events; non-admins do not.
                    //   3. If the event has NO branch_id AND the client IS
                    //      admin → forward (admin sees everything).
                    //   4. If the event has a branch_id matching the client's
                    //      branch → forward (same-branch, normal case).
                    //   5. If the event has a branch_id and the client has NO
                    //      branch (head-office user) → forward (head-office
                    //      sees all branches; the session('branch_id') is
                    //      null for head-office users by convention).
                    $eventBranchId = $payload['branch_id'] ?? null;

                    if ($eventBranchId !== null && $branchId !== null
                        && (int) $eventBranchId !== (int) $branchId) {
                        // Case 1: cross-branch event → skip
                        continue;
                    }

                    if ($eventBranchId === null && !$isAdmin && $branchId !== null) {
                        // Case 2: null-branch event + non-admin + branch-bound
                        // client → skip to prevent cross-branch leak
                        continue;
                    }

                    // Cases 3/4/5: forward
                    $this->sendSseEvent($pgChannel, $payload);
                    $lastHeartbeat = time();
                }

                // Send heartbeat if interval elapsed
                if (time() - $lastHeartbeat >= $heartbeatInterval) {
                    $this->sendSseEvent('heartbeat', [
                        'timestamp' => now()->toISOString(),
                        'uptime_seconds' => time() - $startTime,
                    ]);
                    $lastHeartbeat = time();
                }

                // Sleep briefly to avoid CPU spin
                usleep($pollIntervalUs);
            }

            Log::info('SSE: Client disconnected', [
                'user_id' => $userId,
                'branch_id' => $branchId,
                'uptime_seconds' => time() - $startTime,
            ]);
        }, 200, $headers);

        // Disable output buffering for streaming
        if (ob_get_level()) {
            ob_end_clean();
        }

        return $response;
    }

    /**
     * Get the status of the LISTEN/NOTIFY system.
     *
     * Returns JSON with:
     *   - Whether the PG listener is available
     *   - Active PG channels with listener counts
     *   - Redis connection status
     *   - Worker heartbeat status
     */
    public function status(ListenNotifyService $listenNotify)
    {
        $available = $listenNotify->isAvailable();
        $channels = $available ? $listenNotify->getActiveChannels() : [];

        $redisStatus = 'disconnected';
        try {
            $pong = Redis::connection('default')->ping();
            $redisStatus = $pong ? 'connected' : 'disconnected';
        } catch (\Throwable $e) {
            $redisStatus = 'error: ' . $e->getMessage();
        }

        // Check worker heartbeat
        $workerHeartbeat = null;
        $heartbeatAgeSeconds = null;
        $workerPdoHealthy = null;
        try {
            $heartbeat = Redis::get('rcerp:listen_notify:heartbeat');
            if ($heartbeat) {
                $workerHeartbeat = json_decode($heartbeat, true);

                // G7 (G-211, REALTIME-3): compute the age of the heartbeat in
                // seconds so /sse/status can surface a stale-heartbeat warning
                // before the TTL expires. Previously the only signal was
                // worker_running (bool) — a dead worker still appeared "running"
                // for up to 120s (now 90s per G7 TTL reduction).
                $heartbeatTs = $workerHeartbeat['timestamp'] ?? null;
                if ($heartbeatTs) {
                    try {
                        $heartbeatAgeSeconds = now()->parse($heartbeatTs)->diffInSeconds(now());
                    } catch (\Throwable $e) {
                        $heartbeatAgeSeconds = null;
                    }
                }

                // G12 (G-216, REALTIME-3): surface the worker's self-reported
                // PDO health (written by the worker as `pdo_last_success_at`
                // in the heartbeat JSON).
                $workerPdoHealthy = isset($workerHeartbeat['pdo_last_success_at'])
                    ? true
                    : null;
            }
        } catch (\Throwable $e) {
            $workerHeartbeat = null;
        }

        // G7: a heartbeat is considered stale if its age exceeds 1.5× the
        // heartbeat interval (default 90s — matches the Redis TTL). A stale
        // heartbeat means the worker is either dead or hung in a long
        // pg_notification dequeue.
        $heartbeatTtl = (int) config('realtime.listen_notify.heartbeat_ttl', 90);
        $heartbeatStale = $heartbeatAgeSeconds !== null && $heartbeatAgeSeconds > $heartbeatTtl;

        return response()->json([
            'status' => $available ? 'active' : 'inactive',
            'pg_available' => $available,
            'pg_channels' => $channels,
            'redis_status' => $redisStatus,
            'supported_channels' => ListenNotifyService::PG_CHANNELS,
            'worker_running' => !empty($channels) || ($workerHeartbeat !== null && !$heartbeatStale),
            'worker_heartbeat' => $workerHeartbeat,
            // G7: age of the last heartbeat in seconds. null when no heartbeat
            // has ever been written (worker never started) or the timestamp
            // could not be parsed.
            'last_heartbeat_age_seconds' => $heartbeatAgeSeconds,
            // G7: true when the heartbeat age exceeds the TTL — the worker is
            // likely dead or hung.
            'heartbeat_stale' => $heartbeatStale,
            // G12: worker's self-reported PDO health. true when the worker's
            // dedicated PG connection is healthy (last poll succeeded); false
            // when the worker is in reconnect-backoff; null when the worker
            // predates this field (old heartbeat JSON shape).
            'worker_pdo_healthy' => $workerPdoHealthy,
        ]);
    }

    /**
     * Poll Redis List queues for pending events.
     *
     * Uses RPOP to pull events from per-user, per-branch, and global queues.
     * The ListenNotifyWorker pushes events to these queues via LPUSH.
     *
     * @param string      $userQueueKey
     * @param string|null $branchQueueKey
     * @param string      $globalQueueKey
     * @return array Array of decoded event data
     */
    private function pollRedisQueues(
        string $userQueueKey,
        ?string $branchQueueKey,
        string $globalQueueKey
    ): array {
        $events = [];

        try {
            $redis = Redis::connection('default');

            // Poll per-user queue (highest priority)
            $userEvents = $redis->rpop($userQueueKey, self::POLL_BATCH_SIZE);
            if ($userEvents) {
                foreach ((array) $userEvents as $raw) {
                    $decoded = json_decode($raw, true);
                    if ($decoded) $events[] = $decoded;
                }
            }

            // Poll per-branch queue
            if ($branchQueueKey) {
                $branchEvents = $redis->rpop($branchQueueKey, self::POLL_BATCH_SIZE);
                if ($branchEvents) {
                    foreach ((array) $branchEvents as $raw) {
                        $decoded = json_decode($raw, true);
                        if ($decoded) $events[] = $decoded;
                    }
                }
            }

            // Poll global queue
            $globalEvents = $redis->rpop($globalQueueKey, self::POLL_BATCH_SIZE);
            if ($globalEvents) {
                foreach ((array) $globalEvents as $raw) {
                    $decoded = json_decode($raw, true);
                    if ($decoded) $events[] = $decoded;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('SSE: Redis poll error', [
                'error' => $e->getMessage(),
            ]);
        }

        return $events;
    }

    /**
     * Send an SSE event to the output stream.
     *
     * Format:
     *   event: <event_name>
     *   data: <json_payload>
     *   (blank line)
     *
     * @param string $event  Event name (becomes the SSE event type)
     * @param array  $data   Data payload (JSON-encoded)
     */
    private function sendSseEvent(string $event, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        echo "event: {$event}\n";
        echo "data: {$json}\n\n";

        // Flush output buffers
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }
}
