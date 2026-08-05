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
 *   - Logs heartbeat every 60 seconds
 *   - Reports to Redis key 'rcerp:listen_notify:heartbeat' every 30 seconds
 *   - SSE /sse/status endpoint reports worker status
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

        $this->info('Starting LISTEN/NOTIFY worker...');
        $this->info('  Channels: ' . implode(', ', $channels));
        $this->info('  Dispatch to NotificationService: ' . ($noDispatch ? 'NO' : 'YES'));
        $this->info('  Timeout: ' . ($timeout > 0 ? "{$timeout}s" : 'infinite'));

        Log::info('LISTEN/NOTIFY worker starting', [
            'channels' => $channels,
            'no_dispatch' => $noDispatch,
            'timeout' => $timeout,
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
        foreach ($channels as $channel) {
            $pdo->exec("LISTEN {$channel}");
            $this->info("  LISTEN {$channel}");
        }

        $this->info('Worker is ready. Waiting for notifications...');
        $this->newLine();

        // Set non-blocking mode
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Main event loop
        while (true) {
            // Check timeout
            if ($timeout > 0 && (time() - $startTime) >= $timeout) {
                $this->info('Timeout reached. Shutting down gracefully.');
                break;
            }

            // Poll for notifications (non-blocking with 1-second sleep)
            $this->pollNotifications($pdo, $listenNotify, $notificationService, $noDispatch);

            // Send heartbeat every 60 seconds
            if (time() - $this->lastHeartbeatAt >= 60) {
                $this->sendHeartbeat($listenNotify);
                $this->lastHeartbeatAt = time();
            }

            // Sleep briefly to avoid CPU spin
            usleep(100000); // 100ms
        }

        // Cleanup: UNLISTEN all channels
        foreach ($channels as $channel) {
            $pdo->exec("UNLISTEN {$channel}");
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
     * Poll for pending PostgreSQL notifications.
     *
     * Uses PDO::pgsqlGetNotify() which is non-blocking and returns
     * immediately if no notifications are pending.
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
     * @param ListenNotifyService $listenNotify
     */
    private function sendHeartbeat(ListenNotifyService $listenNotify): void
    {
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
                ]),
                'EX',
                120 // TTL 2 minutes — if worker dies, key expires
            );
        } catch (\Throwable $e) {
            Log::warning('LISTEN/NOTIFY: Heartbeat failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // Also log to console (visible in docker logs)
        $this->line('  ❤ Heartbeat — processed: ' . $this->processedCount . ' notifications');
    }
}
