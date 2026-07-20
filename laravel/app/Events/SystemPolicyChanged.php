<?php

namespace App\Events;

use App\Models\SystemPolicy;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * System Policy Changed Event — Phase 11.
 *
 * Dispatched when a system policy is activated or deactivated.
 * Listeners can:
 *   - Send ERP notifications (to superadmin, compliance officer, etc.)
 *   - Write additional audit records
 *   - Refresh dashboards
 *   - Clear report caches
 *   - Send external alerts (future)
 */
class SystemPolicyChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public SystemPolicy $policy,
        public string $previousMode,
        public string $newMode,
        public int $changedBy
    ) {}
}
