<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 — System Policy Engine.
 *
 * Replaces the legacy "Investigation Mode" boolean with a centralized
 * Compliance & Security Policy Framework.
 *
 * Only one active policy exists at a time. The table stores the full
 * audit trail (activated_by, deactivated_by, timestamps, reason, metadata).
 *
 * Modes:
 *   NORMAL — default, no restrictions
 *   INVESTIGATION — all users (including superadmin) see only current fiscal year data
 *   READ_ONLY — (future) no writes allowed
 *   MAINTENANCE — (future) only superadmin can access
 *   EMERGENCY — (future) system lockdown
 *
 * The architecture allows adding new modes without changing business logic —
 * the middleware, scopes, and service all reference the mode generically.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('system_policies')) {
            Schema::create('system_policies', function (Blueprint $table) {
                $table->id();
                $table->string('mode', 30)->default('NORMAL')->index();
                // NORMAL, INVESTIGATION, READ_ONLY, MAINTENANCE, EMERGENCY
                $table->boolean('is_active')->default(false)->index();
                $table->foreignId('activated_by')->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->foreignId('deactivated_by')->nullable();
                $table->timestamp('deactivated_at')->nullable();
                $table->text('reason')->nullable();
                $table->timestamp('expires_at')->nullable();
                // Auto-deactivate at this time (for timed investigations)
                $table->jsonb('metadata')->nullable();
                // Extensible metadata: fiscal_year_start, fiscal_year_end,
                // restricted_tables, allowed_ips, etc.
                $table->string('activation_source', 30)->default('admin_panel');
                // admin_panel, qr_code (future), mobile_app (future), api (future), scheduled (future)
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->timestamps();

                // Only one active policy — enforced by partial unique index.
                // The service handles activation/deactivation atomically.
            });

            // Partial unique index: only one active policy at a time.
            DB::statement("CREATE UNIQUE INDEX system_policies_one_active ON system_policies (is_active) WHERE is_active = true");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('system_policies');
    }
};
