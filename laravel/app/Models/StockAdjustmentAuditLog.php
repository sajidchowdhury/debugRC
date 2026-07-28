<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stock Adjustment Audit Log — Phase 4 (Stock Adjustment plan).
 *
 * Append-only audit trail for every state transition in the stock-adjustment
 * lifecycle. Written explicitly by {@see \App\Services\Stock\StockAdjustmentAuditLogger}
 * inside the same DB::transaction as the data change — so a rolled-back
 * confirm also rolls back its audit row.
 *
 * This replaces the dead `AuditableMasterData` trait, which never fired
 * because `StockAdjustmentService` writes header/items via `DB::table()`
 * (bypassing Eloquent model events that the trait hooks into). The trait is
 * left on the StockAdjustment model for safety but is documented as
 * superseded — this table is the source of truth for the audit trail.
 *
 * @property int $id
 * @property int $stock_adjustment_id
 * @property int $branch_id denormalized from stock_adjustments for RLS
 * @property string $action create|update|submit|approve|reject|confirm|cancel|reverse|force_confirm|reopen|delete|export|print
 * @property int|null $actor_id users.id of the user who performed the action
 * @property string|null $actor_role snapshot of the actor's role at action time
 * @property array|null $payload action-specific snapshot (jsonb)
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string $created_at
 */
class StockAdjustmentAuditLog extends Model
{
    protected $table = 'stock_adjustment_audit_log';

    /**
     * Append-only: no updated_at column exists on the table.
     */
    public const UPDATED_AT = null;

    public $timestamps = true;

    protected $fillable = [
        'stock_adjustment_id',
        'branch_id',
        'action',
        'actor_id',
        'actor_role',
        'payload',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'stock_adjustment_id' => 'integer',
        'branch_id' => 'integer',
        'actor_id' => 'integer',
    ];

    /**
     * Canonical action allow-list — mirrors the DB-level CHECK constraint
     * `saal_action_check` (see migration
     * 2025_07_30_000001_create_stock_adjustment_audit_log.php). Kept here so
     * the logger, controller, and blade views all read from a single source
     * of truth.
     */
    public const ACTIONS = [
        'create', 'update', 'submit', 'approve', 'reject', 'confirm',
        'cancel', 'reverse', 'force_confirm', 'reopen', 'delete', 'export', 'print',
    ];

    /**
     * Human-readable labels for each action — used by the audit timeline UI.
     */
    public const ACTION_LABELS = [
        'create'         => 'Created',
        'update'         => 'Updated',
        'submit'         => 'Submitted for approval',
        'approve'        => 'Approved',
        'reject'         => 'Rejected',
        'confirm'        => 'Confirmed (posted stock + GL)',
        'cancel'         => 'Cancelled',
        'reverse'        => 'Reversed (stock + GL rolled back)',
        'force_confirm'  => 'Force-confirmed (pipeline override)',
        'reopen'         => 'Reopened',
        'delete'         => 'Deleted',
        'export'         => 'Exported (CSV)',
        'print'          => 'Printed (voucher)',
    ];

    /**
     * Bootstrap badge classes + FontAwesome icons for each action — used by
     * the show-page audit timeline so the badge style is consistent and
     * driven by this central map. Centralised so a future action addition
     * only needs to touch this map.
     */
    public const ACTION_BADGES = [
        'create'         => ['cls' => 'bg-primary-subtle text-primary',     'icon' => 'fa-plus'],
        'update'         => ['cls' => 'bg-info-subtle text-info',           'icon' => 'fa-pen'],
        'submit'         => ['cls' => 'bg-info-subtle text-info',           'icon' => 'fa-paper-plane'],
        'approve'        => ['cls' => 'bg-success-subtle text-success',     'icon' => 'fa-circle-check'],
        'reject'         => ['cls' => 'bg-danger-subtle text-danger',       'icon' => 'fa-circle-xmark'],
        'confirm'        => ['cls' => 'bg-success-subtle text-success',     'icon' => 'fa-check-double'],
        'cancel'         => ['cls' => 'bg-secondary-subtle text-secondary', 'icon' => 'fa-ban'],
        'reverse'        => ['cls' => 'bg-danger-subtle text-danger',       'icon' => 'fa-rotate-left'],
        'force_confirm'  => ['cls' => 'bg-warning-subtle text-warning',     'icon' => 'fa-bolt'],
        'reopen'         => ['cls' => 'bg-warning-subtle text-warning',     'icon' => 'fa-lock-open'],
        'delete'         => ['cls' => 'bg-danger-subtle text-danger',       'icon' => 'fa-trash'],
        'export'         => ['cls' => 'bg-info-subtle text-info',           'icon' => 'fa-file-export'],
        'print'          => ['cls' => 'bg-info-subtle text-info',           'icon' => 'fa-print'],
    ];

    /**
     * The high-impact transitions surfaced in a future "critical events"
     * summary filter. Mirrors the StockTakeAuditLog::isCritical pattern —
     * these are the actions that move stock or GL, so they are the ones an
     * auditor reviews first.
     */
    public const CRITICAL_ACTIONS = ['confirm', 'cancel', 'reverse', 'force_confirm'];

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Human-readable label for an action, used in the audit timeline UI.
     * Falls back to a prettified version of the raw value if the action is
     * somehow not in the canonical map (defensive — the DB CHECK constraint
     * rejects unknown values).
     */
    public static function actionLabel(string $action): string
    {
        return self::ACTION_LABELS[$action]
            ?? ucfirst(str_replace('_', ' ', $action));
    }

    /**
     * Rendered HTML badge for an action — a single call site for the show
     * page's audit timeline. Returns the full <span class="badge ...">…
     * </span> markup so the blade view stays clean.
     */
    public static function actionBadge(string $action): string
    {
        $meta  = self::ACTION_BADGES[$action]
            ?? ['cls' => 'bg-light text-dark', 'icon' => 'fa-circle-question'];
        $label = e(self::actionLabel($action));
        $cls   = e($meta['cls']);
        $icon  = e($meta['icon']);
        return '<span class="badge ' . $cls . '">'
            . '<i class="fas ' . $icon . ' me-1"></i>' . $label
            . '</span>';
    }

    /**
     * True for the high-impact transitions surfaced in a future "critical
     * events" summary (confirm/cancel/reverse/force_confirm).
     */
    public static function isCritical(string $action): bool
    {
        return in_array($action, self::CRITICAL_ACTIONS, true);
    }
}
