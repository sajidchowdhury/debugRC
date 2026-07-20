<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 — Laravel Notifications + Notification Rules.
 *
 * 1. Creates Laravel's standard `notifications` table (overwrites the legacy one
 *    from Phase 2 which had a different schema). Uses UUID primary key per Laravel convention.
 * 2. Creates `notification_rules` — admin-configurable rules that define:
 *    - Which event triggers a notification (sales_finalize, challan_create, etc.)
 *    - Who receives it (role-based: admin, superadmin, sales_manager, accountant, all)
 *    - Whether the rule is active
 *    - How many times it has fired (stats)
 *
 * The admin UI lets users create/edit/delete rules from a dropdown of events + recipients.
 * The event listener checks active rules and dispatches Laravel notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Drop the legacy notifications table (Phase 2 schema) and recreate with Laravel standard.
        if (Schema::hasTable('notifications')) {
            Schema::dropIfExists('notifications');
        }

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('notifiable_id');
            $table->string('notifiable_type');
            $table->string('type'); // Notification class name
            $table->jsonb('data'); // Notification payload
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id']);
        });

        // 2. Notification Rules table.
        if (!Schema::hasTable('notification_rules')) {
            Schema::create('notification_rules', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('event'); // sales_finalize, challan_create, godown_create, payment_receive, soft_delete, accounts_entry, user_login
                $table->string('recipient_type'); // admin, superadmin, sales_manager, accountant, all_users, specific_user
                $table->integer('recipient_user_id')->nullable(); // For specific_user
                $table->string('channel')->default('database'); // database, broadcast (Reverb/WebSocket)
                $table->boolean('is_active')->default(true);
                $table->integer('times_fired')->default(0);
                $table->text('description')->nullable();
                $table->foreignId('created_by')->nullable();
                $table->timestamps();

                $table->index('event');
                $table->index('recipient_type');
                $table->index('is_active');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_rules');
        Schema::dropIfExists('notifications');
    }
};
