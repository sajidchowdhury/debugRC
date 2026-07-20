<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 (Task 17-REFACTOR-AUTOGEN-API): Add api_token column to users.
 *
 * Supports the simple ApiAuth middleware for mobile/AI sidecar integration.
 * The token is a SHA-256 hash of the plain-text bearer token sent by the
 * client (mirrors Laravel Sanctum's plain-text-token → hashed-storage
 * pattern, but kept simple — no personal_access_tokens table needed).
 *
 * Tests can generate a token via User::generateApiToken() (see the model).
 * Clients send it as:  Authorization: Bearer {plain-text-token}
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'api_token')) {
            Schema::table('users', function (Blueprint $table) {
                // 64 chars = sha256 hex hash. NULL = no token issued.
                $table->string('api_token', 64)->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'api_token')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('api_token');
            });
        }
    }
};
