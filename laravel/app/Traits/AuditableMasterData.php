<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Master Data Audit — Phase 4.
 * Replicates legacy app/helpers/MasterDataAuditHelper.php behavior.
 *
 * Logs create/update/delete/restore actions on master-data tables
 * to the user_audit_log table with old/new value snapshots.
 */
trait AuditableMasterData
{
    /**
     * Boot the trait — register Eloquent events for audit logging.
     */
    public static function bootAuditableMasterData(): void
    {
        // Log on create
        static::created(function ($model) {
            static::logAudit($model, 'created', null, $model->getAttributes());
        });

        // Log on update (capture old + new)
        static::updated(function ($model) {
            $old = $model->getOriginal();
            $new = $model->getAttributes();
            // Only log if something actually changed
            if ($model->wasChanged()) {
                $changes = $model->getChanges();
                static::logAudit($model, 'updated', array_intersect_key($old, $changes), $changes);
            }
        });

        // Log on soft-delete
        static::deleted(function ($model) {
            static::logAudit($model, 'deleted', $model->getAttributes(), null);
        });

        // Log on restore — only models that use SoftDeletes have the "restored" event
        if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive(static::class))) {
            static::restored(function ($model) {
                static::logAudit($model, 'restored', null, $model->getAttributes());
            });
        }
    }

    /**
     * Write an audit entry to user_audit_log.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param string $action created|updated|deleted|restored
     * @param array|null $old Old attributes (or null for create)
     * @param array|null $new New attributes (or null for delete)
     */
    protected static function logAudit($model, string $action, ?array $old, ?array $new): void
    {
        try {
            $tableName = $model->getTable();
            $recordId = $model->getKey();
            $userId = Auth::id();
            $branchId = session('branch_id');

            DB::table('user_audit_log')->insert([
                'user_id' => $userId,
                'action' => 'master_data_' . $action,
                'target_user_id' => null,
                'branch_id' => $branchId,
                'record_id' => $recordId,
                'details' => json_encode([
                    'table' => $tableName,
                    'record_id' => $recordId,
                    'old' => $old,
                    'new' => $new,
                ]),
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent() ? mb_substr(request()->userAgent(), 0, 255) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // CRITICAL: Re-throw if inside a DB::transaction(), because a
            // swallowed SQL error leaves PostgreSQL in an aborted state (25P02).
            // Only swallow if we are NOT inside a transaction.
            if (DB::transactionLevel() > 0) {
                throw $e;
            }
            Log::warning('AuditableMasterData: failed to log audit', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Manual audit-logging entry point for services that bypass Eloquent
     * by writing via DB::table(...)->insertGetId / ->update / ->delete.
     *
     * PURCHASING-2 (G-033/G-034/G-035/G-036): the trait's static::created /
     * static::updated / static::deleted listeners ONLY fire on Eloquent model
     * events. When a service uses DB::table('purchase_orders')->insertGetId(...)
     * (as PurchaseOrderService / PurchaseReceiveService / PurchaseReturnService
     * do), no Eloquent event fires, so the master_data_* audit row is never
     * written — leaving a silent gap in the audit team's old/new diff trail.
     *
     * This method lets those services explicitly log the same audit row the
     * trait would have written if Eloquent had been used. Mirrors logAudit()
     * exactly — same DB::table('user_audit_log')->insert payload, same
     * transaction-aware error handling (re-throw inside a DB::transaction()
     * to avoid leaving Postgres in an aborted state; swallow + Log::warning
     * outside).
     *
     * Usage from a service:
     *
     *   // For an INSERT (created):
     *   $poId = DB::table('purchase_orders')->insertGetId($row);
     *   PurchaseOrder::logManualAudit('purchase_orders', $poId, 'created', null, $row);
     *
     *   // For an UPDATE (updated) — capture old BEFORE the update:
     *   $old = (array) DB::table('purchase_orders')->where('id', $poId)->first();
     *   DB::table('purchase_orders')->where('id', $poId)->update($update);
     *   PurchaseOrder::logManualAudit(
     *       'purchase_orders', $poId, 'updated',
     *       array_intersect_key($old, $update),   // only changed columns
     *       $update                                // new values
     *   );
     *
     * @param string   $tableName  Target table name (e.g. 'purchase_orders')
     * @param int      $recordId   PK of the affected row
     * @param string   $action     created|updated|deleted|restored
     * @param array|null $old      Old attributes (null for create/restore)
     * @param array|null $new      New attributes (null for delete)
     */
    public static function logManualAudit(
        string $tableName,
        int $recordId,
        string $action,
        ?array $old,
        ?array $new
    ): void {
        try {
            $userId = Auth::id();
            $branchId = session('branch_id');

            DB::table('user_audit_log')->insert([
                'user_id' => $userId,
                'action' => 'master_data_' . $action,
                'target_user_id' => null,
                'branch_id' => $branchId,
                'record_id' => $recordId,
                'details' => json_encode([
                    'table' => $tableName,
                    'record_id' => $recordId,
                    'old' => $old,
                    'new' => $new,
                ]),
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent() ? mb_substr(request()->userAgent(), 0, 255) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // CRITICAL: Re-throw if inside a DB::transaction(), because a
            // swallowed SQL error leaves PostgreSQL in an aborted state (25P02).
            // Only swallow if we are NOT inside a transaction.
            if (DB::transactionLevel() > 0) {
                throw $e;
            }
            Log::warning('AuditableMasterData: failed to log manual audit', [
                'action'    => $action,
                'table'     => $tableName,
                'record_id' => $recordId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the audit history for this model instance.
     * Used by the audit view.
     */
    public function auditHistory(int $limit = 100): \Illuminate\Support\Collection
    {
        return DB::table('user_audit_log')
            ->whereRaw("details::jsonb->>'table' = ?", [$this->getTable()])
            ->whereRaw("details::jsonb->>'record_id' = ?", [(string) $this->getKey()])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
