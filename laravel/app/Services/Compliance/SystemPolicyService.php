<?php

namespace App\Services\Compliance;

use App\Models\SystemPolicy;
use App\Models\User;
use App\Events\SystemPolicyChanged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Gate;

/**
 * System Policy Service — Phase 11.
 *
 * The single source of truth for system-wide operational policies.
 * Controllers and middleware NEVER read system_policies directly — they
 * call this service.
 *
 * Caching: active policy cached under 'system_policy:active' for 5 min.
 * Events: SystemPolicyChanged dispatched on every activate/deactivate.
 * Audit: every change logged to user_audit_log (immutable).
 */
class SystemPolicyService
{
    private const CACHE_KEY = 'system_policy:active';
    private const CACHE_TTL = 300;

    public function getCurrentPolicy(): ?SystemPolicy
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return SystemPolicy::active()->first();
        });
    }

    public function getCurrentMode(): string
    {
        return $this->getCurrentPolicy()?->mode ?? 'NORMAL';
    }

    public function isInvestigation(): bool
    {
        return $this->getCurrentMode() === 'INVESTIGATION';
    }

    public function isNormal(): bool
    {
        return $this->getCurrentMode() === 'NORMAL';
    }

    public function activate(
        string $mode,
        int $activatedBy,
        string $reason,
        array $metadata = [],
        string $activationSource = 'admin_panel',
        ?\DateTime $expiresAt = null
    ): SystemPolicy {
        if (!array_key_exists($mode, SystemPolicy::MODES)) {
            throw new \InvalidArgumentException("Invalid system policy mode: {$mode}");
        }

        return DB::transaction(function () use ($mode, $activatedBy, $reason, $metadata, $activationSource, $expiresAt) {
            $previousPolicy = SystemPolicy::active()->first();
            $previousMode = $previousPolicy?->mode ?? 'NORMAL';

            if ($previousPolicy) {
                $previousPolicy->update([
                    'is_active' => false,
                    'deactivated_by' => $activatedBy,
                    'deactivated_at' => now(),
                ]);
            }

            $policy = SystemPolicy::create([
                'mode' => $mode,
                'is_active' => true,
                'activated_by' => $activatedBy,
                'activated_at' => now(),
                'reason' => $reason,
                'expires_at' => $expiresAt,
                'metadata' => $metadata,
                'activation_source' => $activationSource,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent() ? mb_substr(request()->userAgent(), 0, 255) : null,
            ]);

            $this->writeAuditLog($activatedBy, $previousMode, $mode, $reason, 'activate');
            Cache::forget(self::CACHE_KEY);
            Cache::put(self::CACHE_KEY, $policy, self::CACHE_TTL);
            SystemPolicyChanged::dispatch($policy, $previousMode, $mode, $activatedBy);

            Log::info('System policy activated', [
                'mode' => $mode, 'previous_mode' => $previousMode,
                'activated_by' => $activatedBy, 'reason' => $reason,
            ]);

            return $policy;
        });
    }

    public function deactivate(int $deactivatedBy, string $reason): bool
    {
        return DB::transaction(function () use ($deactivatedBy, $reason) {
            $policy = SystemPolicy::active()->first();
            if (!$policy) return false;

            $previousMode = $policy->mode;
            $policy->update([
                'is_active' => false,
                'deactivated_by' => $deactivatedBy,
                'deactivated_at' => now(),
            ]);

            $this->writeAuditLog($deactivatedBy, $previousMode, 'NORMAL', $reason, 'deactivate');
            Cache::forget(self::CACHE_KEY);
            SystemPolicyChanged::dispatch($policy, $previousMode, 'NORMAL', $deactivatedBy);

            Log::info('System policy deactivated', [
                'previous_mode' => $previousMode,
                'deactivated_by' => $deactivatedBy, 'reason' => $reason,
            ]);

            return true;
        });
    }

    public function getFiscalYearStart(): ?string
    {
        $policy = $this->getCurrentPolicy();
        if (!$policy || !$policy->isInvestigation()) return null;
        return $policy->getFiscalYearStart();
    }

    public function getFiscalYearEnd(): ?string
    {
        $policy = $this->getCurrentPolicy();
        if (!$policy || !$policy->isInvestigation()) return null;
        return $policy->getFiscalYearEnd();
    }

    private function writeAuditLog(int $userId, string $previousMode, string $newMode, string $reason, string $action): void
    {
        DB::table('user_audit_log')->insert([
            'user_id' => $userId,
            'action' => "system_policy_{$action}",
            'details' => json_encode([
                'previous_mode' => $previousMode,
                'new_mode' => $newMode,
                'reason' => $reason,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]),
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent() ? mb_substr(request()->userAgent(), 0, 255) : null,
            'created_at' => now(),
        ]);
    }

    public function getHistory(int $limit = 50): \Illuminate\Support\Collection
    {
        return SystemPolicy::with(['activatedBy', 'deactivatedBy'])
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();
    }
}
