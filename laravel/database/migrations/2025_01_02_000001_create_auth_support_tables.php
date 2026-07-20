<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: Create password_reset_tokens + remember_tokens tables.
 *
 * These tables are used by Laravel's auth system and are NOT in the
 * baseline schema (Phase 2). They're added here as a separate migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        // password_reset_tokens — SHA-256 hashed tokens, 1hr expiry, single-use.
        if (!Schema::hasTable('password_reset_tokens')) {
            Schema::create('password_reset_tokens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
                $table->string('token_hash', 64);  // sha256 hex
                $table->timestamp('expires_at');
                $table->timestamp('created_at')->useCurrent();
                $table->unique('token_hash');
                $table->index('user_id');
            });
        }

        // remember_tokens — selector:validator scheme (replicates legacy RememberMe.php).
        if (!Schema::hasTable('remember_tokens')) {
            Schema::create('remember_tokens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
                $table->string('selector', 32)->unique();
                $table->string('token_hash', 64);  // sha256 hex
                $table->timestamp('expires_at');
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->nullable();
                $table->index('user_id');
            });
        }

        // Add remember_token column to users (Laravel native remember-me).
        if (!Schema::hasColumn('users', 'remember_token')) {
            Schema::table('users', function (Blueprint $table) {
                $table->rememberToken()->nullable();
            });
        }

        // Add last_login_user_agent column to users (legacy had it, PG schema may not).
        if (!Schema::hasColumn('users', 'last_login_user_agent')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('last_login_user_agent', 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'last_login_user_agent')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('last_login_user_agent');
            });
        }
        if (Schema::hasColumn('users', 'remember_token')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropRememberToken();
            });
        }
        Schema::dropIfExists('remember_tokens');
        Schema::dropIfExists('password_reset_tokens');
    }
};
