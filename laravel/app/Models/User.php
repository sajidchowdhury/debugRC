<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Traits\AuditableMasterData;

/**
 * RC_ERP User model — maps to the legacy `users` table.
 *
 * Phase 0: totp_secret / totp_enabled columns were DROPPED (2FA removed).
 * Phase 3: Eloquent model for Laravel auth + RBAC.
 * Phase 14: added SoftDeletes + AuditableMasterData traits so the
 *           BaseMasterDataController base (withTrashed / restore / audit
 *           log) works for the User admin module.
 *
 * Relationships:
 *  - belongsTo Employee (employee_id)
 *  - Employee belongsTo Branch (branch_id)
 *
 * The role is stored on the Employee (not User), matching legacy schema.
 */
class User extends Authenticatable
{
    use Notifiable, HasFactory, SoftDeletes, AuditableMasterData;

    protected $table = 'users';

    protected $primaryKey = 'id';

    public $timestamps = true;

    // The legacy table uses created_at/updated_at timestamps (no Laravel naming convention issues).
    // Soft deletes: deleted_at column exists.
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'employee_id',
        'username',
        'password_hash',
        'is_active',
        'last_login',
        'last_login_ip',
        'failed_login_count',
        'locked_until',
        'credential_version',
        'telegram_user_id',
        'api_token',
        'created_by',
        'deleted_by',
    ];

    protected $hidden = [
        'password_hash',
        'remember_token',
        'api_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_login' => 'datetime',
        'locked_until' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'failed_login_count' => 'integer',
        'credential_version' => 'integer',
        'telegram_user_id' => 'integer',
    ];

    /**
     * Phase 13: Generate a new API token for this user.
     *
     * Returns the plain-text token — callers MUST give it to the client
     * immediately and not log/store it. The stored column (`api_token`)
     * holds only the SHA-256 hash so a DB leak doesn't expose live tokens.
     *
     * Mirrors Laravel Sanctum's plain-text-token → hashed-storage pattern.
     */
    public function generateApiToken(): string
    {
        $plain = \Illuminate\Support\Str::random(60);

        $this->api_token = hash('sha256', $plain);
        $this->save();

        return $plain;
    }

    /**
     * Phase 13: Find a user by their plain-text bearer token.
     *
     * Hashes the plain text and looks up the matching row in users.api_token.
     * Returns null on no match, disabled user, or soft-deleted user.
     */
    public static function findByApiToken(string $plainToken): ?self
    {
        if ($plainToken === '') {
            return null;
        }

        return static::where('api_token', hash('sha256', $plainToken))
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Legacy app stores the bcrypt hash in `password_hash`, not `password`.
     * Override the Laravel auth password field name.
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    /**
     * Override the auth password column name for Laravel's auth guard.
     */
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /**
     * The remember-me token column (legacy uses a separate remember_tokens table,
     * but for Laravel's native remember-me we use the users.remember_token column).
     */
    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }

    // ===================== RELATIONSHIPS =====================

    public function employee(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    // ===================== ROLE HELPERS =====================

    /**
     * Get the user's role (stored on the Employee, not the User).
     * Falls back to 'user' if employee relationship is not loaded.
     */
    public function getRole(): string
    {
        return $this->employee?->role ?? 'user';
    }

    /**
     * Get the branch_id for this user (from the Employee).
     */
    public function getBranchId(): ?int
    {
        return $this->employee?->branch_id;
    }

    public function isSuperadmin(): bool
    {
        return $this->getRole() === 'superadmin';
    }

    public function isAdmin(): bool
    {
        return in_array($this->getRole(), ['admin', 'superadmin'], true);
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->getRole(), $roles, true);
    }

    /**
     * Check if account is locked.
     */
    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * Scope: active, non-deleted users.
     */
    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true)->whereNull('deleted_at');
    }

    /**
     * Scope: users currently locked (locked_until in the future).
     * Phase 14 — used by UserController::indexStats().
     */
    public function scopeLocked(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereNotNull('locked_until')->where('locked_until', '>', now());
    }

    /**
     * Scope: users with a linked Telegram account.
     * Phase 14 — used by UserController::indexStats().
     */
    public function scopeWithTelegram(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereNotNull('telegram_user_id');
    }
}
