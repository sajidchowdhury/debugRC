<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REPORTS-AUDIT-1 (G-132 / csv-export.md G6) — Create `export_audit_log` table.
 *
 * Append-only audit trail for every CSV/JSON/HTML export performed by an
 * authenticated user. Required for SOX/audit-trail compliance on financial-data
 * exports (invoices, trial balance, GL, budget, branch-demand weekly, etc.) —
 * the existing `fn_financial_audit_trigger` only fires on INSERT/UPDATE/DELETE,
 * NOT on SELECT/COPY, so exports were previously invisible to the audit trail.
 *
 * Schema (per csv-export.md §13 #5):
 *   id BIGSERIAL PRIMARY KEY
 *   user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE
 *   route VARCHAR(255) NOT NULL              — the request path (e.g. admin/purchase-orders/export)
 *   module VARCHAR(100) NOT NULL             — short module label (e.g. 'purchase_orders')
 *   filters_json JSONB                       — the filter inputs (from_date, to_date, branch_id, status, etc.)
 *   row_count INTEGER                        — number of rows exported (0 if unknown for streamed)
 *   byte_size BIGINT                         — byte size of the produced file (0 if unknown for streamed)
 *   ip_address INET                          — request IP (request()->ip())
 *   user_agent TEXT                          — request User-Agent (request()->userAgent())
 *   exported_at TIMESTAMPTZ NOT NULL DEFAULT now()
 *
 * Indexes:
 *   idx_export_audit_log_user    (user_id, exported_at DESC)   — per-user export history
 *   idx_export_audit_log_module  (module, exported_at DESC)    — per-module export frequency / DoS detection
 *
 * Idempotent: Schema::hasTable guard ensures re-running is a no-op.
 *
 * The `exported_at` column uses TIMESTAMPTZ (not the Laravel default
 * `timestamp`) so the audit row records the timezone-aware moment of export
 * (consistent with `user_audit_log.created_at` which is also TIMESTAMPTZ).
 *
 * No RLS on this table — only admins can read it (via the future
 * /admin/export-audit-log page, not yet built — out of scope for this wave).
 * Writes are performed by the WritesExportAuditLog trait, which is applied
 * to controllers behind `role:accountant,manager,admin` (or tighter) middleware.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('export_audit_log')) {
            return;
        }

        DB::statement(<<<SQL
            CREATE TABLE export_audit_log (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                route VARCHAR(255) NOT NULL,
                module VARCHAR(100) NOT NULL,
                filters_json JSONB,
                row_count INTEGER,
                byte_size BIGINT,
                ip_address INET,
                user_agent TEXT,
                exported_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
        SQL);

        DB::statement(
            'CREATE INDEX idx_export_audit_log_user ON export_audit_log(user_id, exported_at DESC)'
        );
        DB::statement(
            'CREATE INDEX idx_export_audit_log_module ON export_audit_log(module, exported_at DESC)'
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('export_audit_log')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS idx_export_audit_log_module');
        DB::statement('DROP INDEX IF EXISTS idx_export_audit_log_user');
        DB::statement('DROP TABLE export_audit_log');
    }
};
