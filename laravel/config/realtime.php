<?php

/**
 * Realtime subsystem configuration (Phase 15 — REALTIME-3, G8/G-212).
 *
 * Centralizes all tunable constants for the LISTEN/NOTIFY worker, the SSE
 * stream, and the Redis queue discipline. Previously these were hardcoded
 * `private const` in `SseController` + `ListenNotifyService` +
 * `ListenNotifyWorker` — tuning required a code change + redeploy. They now
 * read from this config (with `env()` defaults), so operators can tune via
 * `.env` without touching code.
 *
 * Cross-references:
 *   - architecture/realtime-events.md §7.2 (worker), §7.3 (SSE), §14 G8.
 *   - deployment/environment.md for the env-var reference.
 *
 * Naming convention:
 *   - SSE_*       — SseController + browser EventSource tuning.
 *   - LISTEN_NOTIFY_* — worker + ListenNotifyService tuning.
 */

return [

    // -----------------------------------------------------------------------
    // SSE stream (SseController)
    // -----------------------------------------------------------------------
    'sse' => [
        // Seconds between SSE heartbeat events sent to the browser. Keeps the
        // connection alive through proxies + load balancers. The worker's own
        // heartbeat (separate, written to Redis) uses listen_notify.heartbeat_interval.
        'heartbeat_interval' => (int) env('SSE_HEARTBEAT_INTERVAL', 30),

        // Maximum seconds a single SSE connection is allowed to stay open
        // before the client must reconnect. Prevents connection leaks + allows
        // PHP-FPM process recycling.
        'max_connection_time' => (int) env('SSE_MAX_CONNECTION_TIME', 300),

        // Polling interval in microseconds (100000 = 100ms). Balances latency
        // with CPU usage. Lower = faster event delivery + higher CPU; higher =
        // lower CPU + laggier delivery.
        'poll_interval_us' => (int) env('SSE_POLL_INTERVAL_US', 100000),
    ],

    // -----------------------------------------------------------------------
    // LISTEN/NOTIFY worker + Redis queue discipline
    // -----------------------------------------------------------------------
    'listen_notify' => [
        // Seconds between worker heartbeats written to Redis key
        // `rcerp:listen_notify:heartbeat`. The SSE /sse/status endpoint reads
        // this key to report `worker_running`. Keep heartbeat_interval <=
        // heartbeat_ttl / 2 so a single missed beat does not flip the status
        // to "dead" prematurely.
        'heartbeat_interval' => (int) env('LISTEN_NOTIFY_HEARTBEAT_INTERVAL', 60),

        // TTL (seconds) on the Redis heartbeat key. If the worker dies, the key
        // expires after this TTL — so /sse/status reports worker_running=false
        // within `heartbeat_ttl` seconds of the actual death. Set to
        // 1.5× heartbeat_interval (90s default) per G7 — tight enough to
        // surface a dead worker quickly, loose enough to tolerate one missed
        // beat. Previously 120s (2× heartbeat) — too loose.
        'heartbeat_ttl' => (int) env('LISTEN_NOTIFY_HEARTBEAT_TTL', 90),

        // TTL (seconds) on the Redis list keys (global + branch + per-user).
        // If an SSE client disconnects mid-event, the unprocessed events
        // auto-expire after this TTL. 10 minutes covers a typical browser
        // reconnect window.
        'redis_ttl' => (int) env('LISTEN_NOTIFY_REDIS_TTL', 600),

        // Maximum number of events to keep in the GLOBAL Redis list (LPUSH +
        // LTRIM discipline). Older events are evicted. 500 covers a busy
        // 10-minute window for the whole system.
        'global_trim' => (int) env('LISTEN_NOTIFY_GLOBAL_TRIM', 500),

        // Maximum number of events to keep in each BRANCH Redis list. 200
        // covers a busy 10-minute window for a single branch.
        'branch_trim' => (int) env('LISTEN_NOTIFY_BRANCH_TRIM', 200),

        // Maximum number of events to keep in each PER-USER Redis list. 200
        // matches the branch trim — a single user is unlikely to receive more
        // than 200 notifications in a 10-minute window.
        'user_trim' => (int) env('LISTEN_NOTIFY_USER_TRIM', 200),

        // Seconds to sleep between reconnection attempts when the worker's PDO
        // connection dies (G12). Exponential backoff would be over-engineering
        // for a single long-lived connection; a fixed 5s delay gives the PG
        // primary time to come back up without hammering reconnect.
        'reconnect_delay' => (int) env('LISTEN_NOTIFY_RECONNECT_DELAY', 5),

        // Maximum reconnection attempts before the worker gives up + exits
        // (supervisor/systemd then restarts it). 0 = infinite. Default 0 —
        // the process manager is the supervisor; the worker should keep
        // retrying. Set to a positive integer for fail-fast environments.
        'max_reconnect_attempts' => (int) env('LISTEN_NOTIFY_MAX_RECONNECT_ATTEMPTS', 0),
    ],
];
