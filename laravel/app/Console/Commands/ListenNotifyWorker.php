<?php

namespace App\Console\Commands;

use App\Services\Notification\ListenNotifyService;
use App\Services\Notification\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Listen/Notify Worker — Phase 1E (Task 31).
 *
 * Long-running artisan command that LISTENs on PostgreSQL notification
 * channels and forwards events to Redis Pub/Sub and NotificationService.
 *
 * Architecture:
 *   DB Trigger → pg_notify(channel, payload)
 *     → This Worker (LISTEN) → Redis Pub/Sub → SSE Controller → Browser
 *     → This Worker → NotificationService → Database + Broadcast
 *
 * Usage:
 *   php artisan listen-notify:worker
 *   php artisan listen-notify:worker --no-dispatch     # Skip NotificationService forwarding
 *   php artisan listen-notify:worker --channels=rcerp_sales_invoice,rcerp_stock_change
 *
 * The worker opens a dedicated, long-lived PostgreSQL connection that
 * remains in LISTEN mode. When a notification arrives, pg_notification()
 * dequeues it and this command processes it synchronously.
 *
 * Docker deployment:
 *   Add as a separate container or supervisor process that runs:
 *     php artisan listen-notify:worker
 *
 * Health monitoring:
 *   - Logs heartbeat every LISTEN_NOTIFY_HEARTBEAT_INTERVAL seconds (default 60).
 *   - Reports to Redis key 'rcerp:listen_notify:heartbeat' with TTL
 *     LISTEN_NOTIFY_HEARTBEAT_TTL (default 90s — reduced from 120s in
 *     REALTIME-3 G7 so /sse/status surfaces a dead worker faster).
 *   - The heartbeat JSON includes `pdo_last_success_at` (G12) so /sse/status
 *     can report `worker_pdo_healthy` — distinguishes "worker process alive
 *     but PDO stale" from "worker process dead".
 *   - SSE /sse/status endpoint reports worker status + heartbeat age.
 *
 * Reconnection (G12 / G-216, REALTIME-3):
 *   The main event loop wraps pollNotifications() in a try/catch. On
 *   \PDOException (PG primary failover, connection reset, idle-timeout kill),
 *   the worker logs the error, closes the stale PDO, sleeps
 *   LISTEN_NOTIFY_RECONNECT_DELAY seconds (default 5s), opens a fresh
 *   dedicated connection, re-issues LISTEN on every channel, and resumes
 *   polling. Without this, a stale PDO silently returns false from
 *   pgsqlGetNotify() forever while the Redis heartbeat (separate connection)
 *   keeps writing — masking the failure from /sse/status.
 */
class ListenNotifyWorker extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'listen-notify:worker
        {--no-dispatch : Skip forwarding to NotificationService}
        {--channels= : Comma-separated PG channels to listen on (default: all)}
        {--timeout=0 : Max seconds to run (0 = infinite)}';

    /**
     * The console command description.
     */
    protected $description = 'Listen on PostgreSQL NOTIFY channels and forward to Redis Pub/Sub + NotificationService';

    /**
     * Timestamp of the last received notification.
     */
    private int $lastNotificationAt = 0;

    /**
     * Total notifications processed.
     */
    private int $processedCount = 0;

    /**
     * Timestamp of last heartbeat.
     */
    private int $lastHeartbeatAt = 0;

    /**
     * Timestamp of the last successful PDO poll (G12).
     *
     * Written into the heartbeat JSON as `pdo_last_success_at` so /sse/status
     * can report `worker_pdo_healthy`. Reset to null on a caught
     * \PDOException; restored to the current timestamp on the next successful
     * poll. null = worker is in reconnect-backoff or has never polled
     * successfully.
     */
    private ?string $pdoLastSuccessAt = null;

    /**
     * Execute the console command.
     */
    public function handle(
        ListenNotifyService $listenNotify,
        NotificationService $notificationService
    ): int {
        if (!$listenNotify->isAvailable()) {
            $this->error('PostgreSQL LISTEN/NOTIFY is not available (server may be in recovery mode).');
            return self::FAILURE;
        }

        $noDispatch = $this->option('no-dispatch');
        $channelOption = $this->option('channels');
        $timeout = (int) $this->option('timeout');

        // Determine which channels to listen on
        $channels = $channelOption
            ? array_map('trim', explode(',', $channelOption))
            : ListenNotifyService::PG_CHANNELS;

        // G8 (G-212, REALTIME-3): tunables read from config/realtime.php.
        $heartbeatInterval = (int) config('realtime.listen_notify.heartbeat_interval', 60);
        $reconnectDelay = (int) config('realtime.listen_notify.reconnect_delay', 5);
        $maxReconnectAttempts = (int) config('realtime.listen_notify.max_reconnect_attempts', 0);

        $this->info('Starting LISTEN/NOTIFY worker...');
        $this->info('  Channels: ' . implode(', ', $channels));
        $this->info('  Dispatch to NotificationService: ' . ($noDispatch ? 'NO' : 'YES'));
        $this->info('  Timeout: ' . ($timeout > 0 ? "{$timeout}s" : 'infinite'));
        $this->info('  Heartbeat interval: ' . $heartbeatInterval . 's');
        $this->info('  Reconnect delay: ' . $reconnectDelay . 's');

        Log::info('LISTEN/NOTIFY worker starting', [
            'channels' => $channels,
            'no_dispatch' => $noDispatch,
            'timeout' => $timeout,
            'heartbeat_interval' => $heartbeatInterval,
        ]);

        $startTime = time();
        $this->lastHeartbeatAt = time();
        $this->lastNotificationAt = time();

        // Get a dedicated, long-lived PDO connection for LISTEN
        $pdo = $this->getDedicatedConnection();

        if (!$pdo) {
            $this->error('Failed to establish dedicated PostgreSQL connection.');
            return self::FAILURE;
        }

        // Issue LISTEN commands for each channel
        $this->issueListenCommands($pdo, $channels);

        $this->info('Worker is ready. Waiting for notifications...');
        $this->newLine();

        // Main event loop — G12: wraps pollNotifications in try/catch with
        // reconnection logic. On \PDOException, reconnect + re-LISTEN +
        // resume. The loop counter bounds the reconnection attempts when
        // max_reconnect_attempts > 0 (default 0 = infinite — the process
        // manager is the supervisor).
        $reconnectAttempts = 0;

        while (true) {
            // Check timeout
            if ($timeout > 0 && (time() - $startTime) >= $timeout) {
                $this->info('Timeout reached. Shutting down gracefully.');
                break;
            }

            try {
                // Poll for notifications (non-blocking with 1-second sleep)
                $this->pollNotifications($pdo, $listenNotify, $notificationService, $noDispatch);

                // G12: a successful poll (or clean empty return) means the PDO
                // is still healthy. Record the timestamp for the heartbeat.
                $this->pdoLastSuccessAt = now()->toISOString();

                // Reset the reconnect-attempt counter on a successful poll —
                // we're back to a healthy state.
                $reconnectAttempts = 0;
            } catch (\PDOException $e) {
                // G12: the PDO died (PG failover, connection reset, idle-timeout
                // kill). Log + close + reconnect + re-LISTEN. Without this, the
                // worker would silently return false from pgsqlGetNotify()
                // forever while the Redis heartbeat kept writing — masking the
                // failure from /sse/status.
                $reconnectAttempts++;
                Log::error('LISTEN/NOTIFY: PDO error during poll — attempting reconnect', [
                    'error' => $e->getMessage(),
                    'attempt' => $reconnectAttempts,
                    'max_attempts' => $maxReconnectAttempts === 0 ? 'infinite' : $maxReconnectAttempts,
                ]);
                $this->error('  PDO error: ' . $e->getMessage());
                $this->warn('  Attempting reconnect #' . $reconnectAttempts . '...');

                // Mark PDO as unhealthy so the next heartbeat reflects it.
                $this->pdoLastSuccessAt = null;

                // Bail if the operator set a finite reconnect budget.
                if ($maxReconnectAttempts > 0 && $reconnectAttempts > $maxReconnectAttempts) {
                    $this->error('  Max reconnect attempts (' . $maxReconnectAttempts . ') exceeded. Exiting.');
                    Log::critical('LISTEN/NOTIFY worker: max reconnect attempts exceeded, exiting', [
                        'attempts' => $reconnectAttempts,
                    ]);
                    return self::FAILURE;
                }

                // Sleep before reconnecting (gives the PG primary time to come
                // back up without hammering reconnect).
                sleep($reconnectDelay);

                // Close the stale PDO + open a fresh one.
                $pdo = null; // release the reference; PHP GC closes the socket
                $pdo = $this->getDedicatedConnection();

                if (!$pdo) {
                    // Reconnect failed — loop again (the try/catch will catch
                    // the next failure on poll). The heartbeat below will still
                    // fire, reporting pdo_last_success_at=null so /sse/status
                    // surfaces the unhealthy state.
                    $this->warn('  Reconnect failed. Will retry on next iteration.');
                    continue;
                }

                // Re-issue LISTEN on the fresh connection.
                $this->issueListenCommands($pdo, $channels);
                $this->info('  Reconnected + re-LISTEN. Resuming poll loop.');
            } catch (\Throwable $e) {
                // Non-PDO exception — log + keep looping so a single bad
                // payload does not kill the worker.
                Log::error('LISTEN/NOTIFY: Unexpected error during poll', [
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);
                $this->error('  Unexpected error: ' . $e->getMessage());
            }

            // Send heartbeat every $heartbeatInterval seconds (G7: was
            // hardcoded 60s; now reads from config, default still 60s).
            if (time() - $this->lastHeartbeatAt >= $heartbeatInterval) {
                $this->sendHeartbeat($listenNotify);
                $this->lastHeartbeatAt = time();
            }

            // Sleep briefly to avoid CPU spin (G8: was hardcoded 100000μs; the
            // SSE poll interval config is reused here since both loops poll at
            // the same cadence — a worker poll is cheap, but 100ms is a sane
            // floor).
            usleep((int) config('realtime.sse.poll_interval_us', 100000));
        }

        // Cleanup: UNLISTEN all channels
        try {
            foreach ($channels as $channel) {
                $pdo->exec("UNLISTEN {$channel}");
            }
        } catch (\Throwable $e) {
            // Best-effort cleanup — the connection may already be closed.
        }

        $this->info("Worker stopped. Processed {$this->processedCount} notifications.");
        Log::info('LISTEN/NOTIFY worker stopped', [
            'processed_count' => $this->processedCount,
            'uptime_seconds' => time() - $startTime,
        ]);

        return self::SUCCESS;
    }

    /**
     * Get a dedicated PostgreSQL PDO connection for LISTEN.
     *
     * Must be a separate connection from Laravel's connection pool
     * because LISTEN requires a persistent, non-pooled connection.
     * The connection stays in "idle in transaction" state while waiting.
     *
     * @return \PDO|null
     */
    private function getDedicatedConnection(): ?\PDO
    {
        try {
            $host = config('database.connections.pgsql.host');
            $port = config('database.connections.pgsql.port');
            $database = config('database.connections.pgsql.database');
            $username = config('database.connections.pgsql.username');
            $password = config('database.connections.pgsql.password');

            $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
            $pdo = new \PDO($dsn, $username, $password);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $this->info('  Dedicated PG connection established.');
            return $pdo;
        } catch (\Throwable $e) {
            Log::error('LISTEN/NOTIFY worker: Failed to connect to PostgreSQL', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Issue LISTEN commands for each channel on the given PDO connection.
     *
     * G12 (G-216, REALTIME-3): factored out of handle() so the reconnection
     * path can re-issue LISTEN on a fresh PDO without duplicating the loop.
     */
    private function issueListenCommands(\PDO $pdo, array $channels): void
    {
        foreach ($channels as $channel) {
            $pdo->exec("LISTEN {$channel}");
            $this->info("  LISTEN {$channel}");
        }
    }

    /**
     * Poll for pending PostgreSQL notifications.
     *
     * Uses PDO::pgsqlGetNotify() which is non-blocking and returns
     * immediately if no notifications are pending.
     *
     * G12 (G-216, REALTIME-3): this method is called inside a try/catch in
     * handle(). A stale PDO will raise \PDOException here — the caller
     * handles reconnection.
     *
     * @param \PDO                  $pdo
     * @param ListenNotifyService   $listenNotify
     * @param NotificationService   $notificationService
     * @param bool                  $noDispatch
     */
    private function pollNotifications(
        \PDO $pdo,
        ListenNotifyService $listenNotify,
        NotificationService $notificationService,
        bool $noDispatch
    ): void {
        // pgsqlGetNotify() returns false if no notification pending,
        // or ['message' => payload, 'pid' => notifier_pid, 'payload' => payload]
        while ($notification = $pdo->pgsqlGetNotify(\PDO::FETCH_ASSOC, 0)) {
            $payload = $notification['payload'] ?? null;
            $pid = $notification['pid'] ?? 0;

            if (!$payload) {
                continue;
            }

            $this->processedCount++;
            $this->lastNotificationAt = time();

            // Decode the JSON payload
            $data = json_decode($payload, true);
            if (!$data) {
                Log::warning('LISTEN/NOTIFY: Invalid JSON payload', [
                    'payload' => $payload,
                    'pid' => $pid,
                ]);
                continue;
            }

            $channel = $notification['message'] ?? 'unknown';

            $this->info("  [{$this->processedCount}] Channel: {$channel} | Table: " .
                ($data['table'] ?? '?') . " | Action: " . ($data['action'] ?? '?') .
                " | ID: " . ($data['id'] ?? '?'));

            // 1. Publish to Redis Pub/Sub (for SSE clients)
            $listenNotify->publishToRedis($channel, $data);

            // 2. Forward to NotificationService (for rule-based dispatch)
            //    G-076/G-078/G-079 (WORKFLOWS-NOTIFICATION): the
            //    worker-forward path is disabled — CHANNEL_EVENT_MAP is
            //    empty, so forwardToNotificationService() early-returns.
            //    Rule-based notification dispatch is handled by direct PHP
            //    calls in the service layer (SalesInvoiceService,
            //    SalesChallanService, CustomerPaymentService,
            //    SalesReturnService) which carry full $context. The call is
            //    retained for backward compatibility + future re-enablement
            //    if trigger payloads are ever enriched.
            if (!$noDispatch) {
                $listenNotify->forwardToNotificationService($channel, $data, $notificationService);
            }

            // Log for audit trail
            Log::info('LISTEN/NOTIFY: Notification processed', [
                'channel' => $channel,
                'table' => $data['table'] ?? null,
                'action' => $data['action'] ?? null,
                'id' => $data['id'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'notifier_pid' => $pid,
                'processed_count' => $this->processedCount,
            ]);
        }
    }

    /**
     * Send a heartbeat signal to Redis.
     *
     * Updates a Redis key that the SSE status endpoint can check
     * to verify the worker is alive.
     *
     * G7 (G-211, REALTIME-3): TTL reduced from 120s to 90s (1.5× the 60s
     * heartbeat interval) so /sse/status surfaces a dead worker within 90s
     * instead of 120s. The TTL is read from config/realtime.php
     * (LISTEN_NOTIFY_HEARTBEAT_TTL, default 90).
     *
     * G12 (G-216, REALTIME-3): the heartbeat JSON now includes
     * `pdo_last_success_at` — null when the worker's PDO is in
     * reconnect-backoff, an ISO timestamp when the last poll succeeded.
     * /sse/status surfaces this as `worker_pdo_healthy`.
     *
     * @param ListenNotifyService $listenNotify
     */
    private function sendHeartbeat(ListenNotifyService $listenNotify): void
    {
        // G7: TTL read from config (was hardcoded 120).
        $heartbeatTtl = (int) config('realtime.listen_notify.heartbeat_ttl', 90);

        try {
            \Illuminate\Support\Facades\Redis::set(
                'rcerp:listen_notify:heartbeat',
                json_encode([
                    'timestamp' => now()->toISOString(),
                    'processed_count' => $this->processedCount,
                    'last_notification_at' => $this->lastNotificationAt > 0
                        ? now()->setTimestamp($this->lastNotificationAt)->toISOString()
                        : null,
                    'pid' => getmypid(),
                    // G12: self-reported PDO health. null = reconnect-backoff
                    // or never-polled; ISO timestamp = last successful poll.
                    'pdo_last_success_at' => $this->pdoLastSuccessAt,
                ]),
                'EX',
                $heartbeatTtl
            );
        } catch (\Throwable $e) {
            Log::warning('LISTEN/NOTIFY: Heartbeat failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // Also log to console (visible in docker logs)
        $pdoStatus = $this->pdoLastSuccessAt ? 'pdo:ok' : 'pdo:RECONNECTING';
        $this->line('  ❤ Heartbeat — processed: ' . $this->processedCount . ' notifications (' . $pdoStatus . ')');
    }
}
