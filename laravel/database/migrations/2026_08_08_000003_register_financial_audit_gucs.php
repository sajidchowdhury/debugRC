<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1.3 — Register custom GUCs for financial audit trail context.
 *
 * These GUCs are set by SetAppBranchId middleware on every request and
 * consumed by fn_financial_audit_trigger() to capture request context
 * in the financial_audit_log table.
 *
 * GUCs registered:
 *   - app.request_path: The URL path of the HTTP request
 *   - app.request_ip: The client IP address
 *   - app.request_id: A unique request identifier (from X-Request-ID header or generated)
 */
return new class extends Migration
{
    public function up(): void
    {
        // These GUCs are auto-available in PostgreSQL 9.2+ when set with SET.
        // No explicit registration needed — the SET command in the middleware
        // creates them on the fly. This migration exists as documentation
        // and to verify the GUCs work correctly.

        // Verify that we can set and read these GUCs.
        try {
            DB::unprepared("SET app.request_path = 'test'");
            DB::unprepared("SET app.request_ip = '127.0.0.1'");
            DB::unprepared("SET app.request_id = 'test-req-id'");

            $result = DB::selectOne("SELECT current_setting('app.request_path', true) AS path");
            if ($result && $result->path === 'test') {
                \Illuminate\Support\Facades\Log::info('Phase 1.3: Financial audit GUCs verified successfully.');
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Phase 1.3: Financial audit GUCs not available yet: ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        // GUCs are session-scoped; no persistent cleanup needed.
    }
};
