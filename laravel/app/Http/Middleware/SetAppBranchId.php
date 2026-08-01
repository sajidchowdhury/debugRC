<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Set app.branch_id PostgreSQL Session Variable — Task 19.
 *
 * Sets the `app.branch_id` custom GUC (Grand Unified Configuration) parameter
 * on every authenticated web request. This parameter is consumed by Row-Level
 * Security (RLS) policies to enforce branch isolation at the database level.
 *
 * Flow:
 *   1. User authenticates → SyncLegacySession populates session('branch_id')
 *   2. This middleware runs → SET app.branch_id = <session_branch_id>
 *   3. Every subsequent SQL query in the request is filtered by RLS policies:
 *      USING (branch_id = current_setting('app.branch_id')::int)
 *   4. Admin/superadmin users get app.is_admin = true, allowing RLS bypass
 *
 * The GUC is set per-connection and automatically resets when the connection
 * is returned to the pool. No explicit RESET is needed.
 *
 * Console commands: This middleware does NOT run for artisan commands.
 * For CLI contexts, use `DB::unprepared("SET app.branch_id = " . (int)$branchId)`
 * manually before running branch-scoped queries, or run unscoped (admin mode).
 * (PostgreSQL SET does NOT accept PDO bound parameters — never use ? placeholders.)
 *
 * Defense-in-depth layers:
 *   Layer 1 (Query):  BranchScope Eloquent global scope — filters reads
 *   Layer 2 (Route):  EnforceBranchIsolation middleware — validates writes
 *   Layer 3 (DB):     RLS policies — cannot be bypassed even by raw SQL
 */
class SetAppBranchId
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $user = Auth::user();
            $branchId = (int) (session('branch_id') ?? $user->getBranchId() ?? 0);
            $isAdmin = $user->isAdmin() ? 'true' : 'false';

            try {
                // Set the branch_id GUC for RLS policies.
                // Using SET LOCAL would scope to transaction only; SET scopes
                // to the session (connection), which persists for all queries
                // in this request but resets when connection is recycled.
                //
                // NOTE: PostgreSQL SET does NOT accept bound parameters (?/$1).
                // PDO::prepare() would fail with: "syntax error at or near \"$1\"".
                // The values are already safely cast (int / known 'true'|'false'
                // string), so inlining is SQL-injection-safe. Use DB::unprepared()
                // to send the statement as-is without PDO binding.
                $safeBranchId = (int) ($branchId > 0 ? $branchId : 0);
                $safeIsAdmin  = $isAdmin === 'true' ? 'true' : 'false';
                DB::unprepared("SET app.branch_id = {$safeBranchId}");
                DB::unprepared("SET app.is_admin = {$safeIsAdmin}");

                // Phase 1.3: Set request context for financial audit trail triggers.
                // These GUCs are read by fn_financial_audit_trigger() to capture
                // the request path, IP, and request ID in the audit log.
                $safePath = addcslashes($request->path() ?? '', "'\\");
                $safeIp   = addcslashes($request->ip() ?? '', "'\\");
                $safeRid  = addcslashes($request->header('X-Request-ID') ?? ($request->input('request_id') ?? uniqid('req_', true)), "'\\");
                DB::unprepared("SET app.request_path = '{$safePath}'");
                DB::unprepared("SET app.request_ip = '{$safeIp}'");
                DB::unprepared("SET app.request_id = '{$safeRid}'");
            } catch (\Throwable $e) {
                // If GUC doesn't exist yet (migration not run), silently skip.
                // This allows code deployment before migration.
                \Illuminate\Support\Facades\Log::debug(
                    'SET app.branch_id failed (migration may not be run yet): ' . $e->getMessage()
                );
            }
        }

        return $next($request);
    }
}
