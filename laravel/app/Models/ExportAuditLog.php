<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Export Audit Log — REPORTS-AUDIT-1 (G-132 / csv-export.md G6).
 *
 * Append-only audit trail for every CSV/JSON/HTML export performed by an
 * authenticated user. Written by the {@see \App\Http\Controllers\Concerns\WritesExportAuditLog}
 * trait from any controller that performs a CSV/JSON/HTML export.
 *
 * Required for SOX/audit-trail compliance on financial-data exports
 * (invoices, trial balance, GL, budget, branch-demand weekly, etc.) —
 * the existing `fn_financial_audit_trigger` only fires on INSERT/UPDATE/DELETE,
 * NOT on SELECT/COPY, so exports were previously invisible to the audit trail.
 *
 * Schema: see migration 2026_09_06_000001_create_export_audit_log_table.php.
 *
 * @property int $id
 * @property int $user_id users.id of the user who performed the export
 * @property string $route Request path (e.g. 'admin/purchase-orders/export')
 * @property string $module Short module label (e.g. 'purchase_orders')
 * @property array|null $filters_json Filter inputs as a JSONB-decoded array
 * @property int|null $row_count Number of rows exported (0 if unknown for streamed)
 * @property int|null $byte_size Byte size of the produced file (0 if unknown for streamed)
 * @property string|null $ip_address Request IP (INET column)
 * @property string|null $user_agent Request User-Agent
 * @property \Carbon\Carbon $exported_at Timezone-aware export timestamp
 */
class ExportAuditLog extends Model
{
    protected $table = 'export_audit_log';

    /**
     * Append-only: no updated_at column exists on the table.
     */
    public const UPDATED_AT = null;

    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'route',
        'module',
        'filters_json',
        'row_count',
        'byte_size',
        'ip_address',
        'user_agent',
        'exported_at',
    ];

    protected $casts = [
        'filters_json' => 'array',
        'user_id' => 'integer',
        'row_count' => 'integer',
        'byte_size' => 'integer',
        'exported_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
