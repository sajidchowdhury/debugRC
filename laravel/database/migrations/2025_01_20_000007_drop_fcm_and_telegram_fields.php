<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

/**
 * Drop FCM tokens table and telegram_user_id column.
 *
 * The project now uses Laravel's native push notification system
 * (database + broadcast channels via ERPNotification) instead of
 * the custom Telegram and Firebase Cloud Messaging (FCM) integrations.
 *
 * This migration:
 *   1. Drops the fcm_tokens table entirely (no longer needed)
 *   2. Drops the users.telegram_user_id column (no longer needed)
 *   3. Drops the users_telegram_user_id index if it exists
 *
 * All notification delivery now goes through Laravel's Notifiable
 * trait on the User model → ERPNotification → NotificationService.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop fcm_tokens table (FCM push notification token storage)
        DB::statement('DROP TABLE IF EXISTS fcm_tokens CASCADE');

        // Drop telegram_user_id column from users
        DB::statement('ALTER TABLE users DROP COLUMN IF EXISTS telegram_user_id');

        DB::statement('ANALYZE');
    }

    public function down(): void
    {
        // Re-create fcm_tokens table
        DB::statement('
            CREATE TABLE fcm_tokens (
                id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                user_id integer NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                fcm_token varchar(255) NOT NULL,
                device_info text,
                created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
                updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fcm_unique_user_token UNIQUE (user_id, fcm_token)
            )
        ');

        // Re-add telegram_user_id column
        DB::statement('ALTER TABLE users ADD COLUMN telegram_user_id bigint');

        DB::statement('ANALYZE');
    }
};
