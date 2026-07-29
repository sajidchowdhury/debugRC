<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stock Take Audit Log — Phase 2 (Stock Take plan).
 *
 * Append-only audit trail for every state transition in the stock-take
 * lifecycle. Written explicitly by {@see \App\Services\Stock\StockTakeAuditLogger}
 * inside the same DB::transaction as the data change — so a rolled-back
 * post also rolls back its audit row.
 *
 * This replaces the dead `AuditableMasterData` trait, which never fired
 * because `StockTakeService` writes via `DB::table()` (bypassing Eloquent
 * model events that the trait hooks into).
 *
 * @property int $id
 * @property int $stock_take_session_id
 * @property int|null $stock_take_warehouse_id
 * @property int|null $stock_take_item_id
 * @property string $action create|setup|save_count|mark_complete|submit|approve|reject|post|reverse|re_open|delete|cancel|recount|scan_count|bulk_upsert|csv_import|autosave
 * @property int|null $actor_id users.id of the user who performed the action
 * @property string|null $from_status
 * @property string|null $to_status
 * @property array|null $payload
 * @property int $branch_id denormalized from stock_take_sessions for RLS
 * @property string $created_at
 */
class StockTakeAuditLog extends Model
{
    protected $table = 'stock_take_audit_log';

    /**
     * Append-only: no updated_at column exists on the table.
     */
    public const UPDATED_AT = null;

    public $timestamps = true;

    protected $fillable = [
        'stock_take_session_id',
        'stock_take_warehouse_id',
        'stock_take_item_id',
        'action',
        'actor_id',
        'from_status',
        'to_status',
        'payload',
        'branch_id',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'stock_take_session_id' => 'integer',
        'stock_take_warehouse_id' => 'integer',
        'stock_take_item_id' => 'integer',
        'actor_id' => 'integer',
        'branch_id' => 'integer',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(StockTakeSession::class, 'stock_take_session_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'stock_take_warehouse_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'actor_id');
    }

    /**
     * Human-readable label for an action, used in the audit timeline UI.
     */
    public static function actionLabel(string $action): string
    {
        return [
            'create'        => 'Session created',
            'setup'         => 'Counts set up',
            'save_count'    => 'Counts saved',
            'mark_complete' => 'Warehouse marked complete',
            'submit'        => 'Submitted for approval',
            'approve'       => 'Approved',
            'reject'        => 'Rejected',
            'post'          => 'Posted (variances applied + GL)',
            'reverse'       => 'Reversed (stock + GL rolled back)',
            're_open'       => 'Re-opened after reversal',
            'delete'        => 'Deleted',
            'cancel'        => 'Cancelled',
            // Phase 7: count-UX actions.
            'recount'       => 'Warehouse recounted',
            'scan_count'    => 'Barcode scan saved a line',
            'bulk_upsert'   => 'Bulk paste upserted lines',
            'csv_import'    => 'CSV import upserted lines',
            'autosave'      => 'Line auto-saved',
        ][$action] ?? ucfirst(str_replace('_', ' ', $action));
    }

    /**
     * Bootstrap-appropriate color bucket for an action badge.
     */
    public static function actionColor(string $action): string
    {
        return [
            'create'        => 'primary',
            'setup'         => 'info',
            'save_count'    => 'info',
            'mark_complete' => 'info',
            'submit'        => 'secondary',
            'approve'       => 'success',
            'reject'        => 'danger',
            'post'          => 'success',
            'reverse'       => 'danger',
            're_open'       => 'warning',
            'delete'        => 'danger',
            'cancel'        => 'secondary',
            // Phase 7: count-UX actions.
            'recount'       => 'warning',
            'scan_count'    => 'info',
            'bulk_upsert'   => 'info',
            'csv_import'    => 'info',
            'autosave'      => 'secondary',
        ][$action] ?? 'secondary';
    }

    /**
     * True for the high-impact transitions surfaced in the "critical events"
     * summary on the session detail page.
     */
    public static function isCritical(string $action): bool
    {
        // Phase 7: 'recount' added — a warehouse-level state change worth
        // flagging in the critical-events summary alongside post/reverse.
        return in_array($action, ['post', 'reverse', 're_open', 'recount'], true);
    }
}
