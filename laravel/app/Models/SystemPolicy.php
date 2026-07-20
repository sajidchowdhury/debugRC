<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * System Policy — Phase 11.
 *
 * Represents a system-wide operational policy (NORMAL, INVESTIGATION, etc.).
 * Only one policy can be active at a time (enforced by partial unique index).
 *
 * The active policy is cached by SystemPolicyService for fast lookups.
 * Controllers never read this table directly — they use the service.
 *
 * Modes:
 *   NORMAL — default, no restrictions
 *   INVESTIGATION — all users (including superadmin) see only current fiscal year
 *   READ_ONLY — (future) no writes allowed
 *   MAINTENANCE — (future) only superadmin can access
 *   EMERGENCY — (future) system lockdown
 *
 * @property int $id
 * @property string $mode
 * @property bool $is_active
 * @property int|null $activated_by
 * @property string|null $activated_at
 * @property int|null $deactivated_by
 * @property string|null $deactivated_at
 * @property string|null $reason
 * @property string|null $expires_at
 * @property array|null $metadata
 * @property string $activation_source
 * @property string|null $ip_address
 * @property string|null $user_agent
 */
class SystemPolicy extends Model
{
    protected $table = 'system_policies';

    protected $fillable = [
        'mode', 'is_active', 'activated_by', 'activated_at',
        'deactivated_by', 'deactivated_at', 'reason', 'expires_at',
        'metadata', 'activation_source', 'ip_address', 'user_agent',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'activated_at' => 'datetime',
        'deactivated_at' => 'datetime',
        'expires_at' => 'datetime',
        'metadata' => 'array',
        'activated_by' => 'integer',
        'deactivated_by' => 'integer',
    ];

    public const MODES = [
        'NORMAL' => 'Normal Operation',
        'INVESTIGATION' => 'Investigation Mode',
        'READ_ONLY' => 'Read-Only Mode (Future)',
        'MAINTENANCE' => 'Maintenance Mode (Future)',
        'EMERGENCY' => 'Emergency Lockdown (Future)',
    ];

    public const ACTIVATION_SOURCES = [
        'admin_panel' => 'Admin Panel',
        'qr_code' => 'QR Code (Future)',
        'mobile_app' => 'Mobile App (Future)',
        'api' => 'API (Future)',
        'scheduled' => 'Scheduled Task (Future)',
    ];

    /**
     * Scope: active policies only.
     */
    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the mode label.
     */
    public function getModeLabelAttribute(): string
    {
        return self::MODES[$this->mode] ?? $this->mode;
    }

    /**
     * Is this an investigation mode policy?
     */
    public function isInvestigation(): bool
    {
        return $this->mode === 'INVESTIGATION';
    }

    /**
     * Is this a normal mode policy (no restrictions)?
     */
    public function isNormal(): bool
    {
        return $this->mode === 'NORMAL';
    }

    /**
     * Get the fiscal year start date from metadata, or compute from current date.
     * Bangladesh fiscal year: July 1 → June 30.
     *
     * @return string Y-m-d
     */
    public function getFiscalYearStart(): string
    {
        if (isset($this->metadata['fiscal_year_start'])) {
            return $this->metadata['fiscal_year_start'];
        }

        $now = now();
        $year = $now->month >= 7 ? $now->year : $now->year - 1;
        return "{$year}-07-01";
    }

    /**
     * Get the fiscal year end date.
     *
     * @return string Y-m-d
     */
    public function getFiscalYearEnd(): string
    {
        if (isset($this->metadata['fiscal_year_end'])) {
            return $this->metadata['fiscal_year_end'];
        }

        $start = $this->getFiscalYearStart();
        return \Carbon\Carbon::parse($start)->addYear()->subDay()->format('Y-m-d');
    }

    public function activatedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function deactivatedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }
}
