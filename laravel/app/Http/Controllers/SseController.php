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
 * Heartbeat is sent every 30 seconds to keep the connection alive
 * through proxies and load balancers.
 */
class SseController extends Controller
{
    /**
     * Heartbeat interval in seconds.
     */
    private const HEARTBEAT_INTERVAL = 30;

    /**
     * Maximum time (seconds) a single SSE connection is allowed
     * to stay open before the client must reconnect. Prevents
     * connection leaks and allows PHP-FPM process recycling.
     */
    private const MAX_CONNECTION_TIME = 300; // 5 minutes

    /**
     * Polling interval in microseconds (100ms = 100000μs).
     * Balances latency with CPU usage.
     */
    private const POLL_INTERVAL_US = 100000;

    /**
     * Redis key TTL for event queues (auto-cleanup if SSE client disconnects).
     */
    private const QUEUE_TTL = 600; // 10 minutes

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

        // Use Laravel's streaming response
        $response = response()->stream(function () use (
            $userId, $branchId, $userQueueKey, $branchQueueKey,
            $globalQueueKey, $startTime, &$lastHeartbeat
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
                if (time() - $startTime >= self::MAX_CONNECTION_TIME) {
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

                    // Skip events from other branches (branch isolation)
                    $eventBranchId = $payload['branch_id'] ?? null;
                    if ($eventBranchId && $branchId && (int) $eventBranchId !== (int) $branchId) {
                        // For global queue, check branch filtering
                        // User-specific and branch-specific queues are already filtered
                        continue;
                    }

                    // Forward the event to the SSE client
                    $this->sendSseEvent($pgChannel, $payload);
                    $lastHeartbeat = time();
                }

                // Send heartbeat if interval elapsed
                if (time() - $lastHeartbeat >= self::HEARTBEAT_INTERVAL) {
                    $this->sendSseEvent('heartbeat', [
                        'timestamp' => now()->toISOString(),
                        'uptime_seconds' => time() - $startTime,
                    ]);
                    $lastHeartbeat = time();
                }

                // Sleep briefly to avoid CPU spin
                usleep(self::POLL_INTERVAL_US);
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
        try {
            $heartbeat = Redis::get('rcerp:listen_notify:heartbeat');
            if ($heartbeat) {
                $workerHeartbeat = json_decode($heartbeat, true);
            }
        } catch (\Throwable $e) {
            $workerHeartbeat = null;
        }

        return response()->json([
            'status' => $available ? 'active' : 'inactive',
            'pg_available' => $available,
            'pg_channels' => $channels,
            'redis_status' => $redisStatus,
            'supported_channels' => ListenNotifyService::PG_CHANNELS,
            'worker_running' => !empty($channels) || $workerHeartbeat !== null,
            'worker_heartbeat' => $workerHeartbeat,
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
            $userEvents = $redis->rpop($userQueueKey, 10);
            if ($userEvents) {
                foreach ((array) $userEvents as $raw) {
                    $decoded = json_decode($raw, true);
                    if ($decoded) $events[] = $decoded;
                }
            }

            // Poll per-branch queue
            if ($branchQueueKey) {
                $branchEvents = $redis->rpop($branchQueueKey, 10);
                if ($branchEvents) {
                    foreach ((array) $branchEvents as $raw) {
                        $decoded = json_decode($raw, true);
                        if ($decoded) $events[] = $decoded;
                    }
                }
            }

            // Poll global queue
            $globalEvents = $redis->rpop($globalQueueKey, 10);
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
