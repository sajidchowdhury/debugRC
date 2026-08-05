<?php

namespace App\Http\Controllers\Admin;

use App\Facades\CsvExporter;
use App\Http\Controllers\Concerns\WritesExportAuditLog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\GlobalAuditLogRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Global Audit Log Viewer — Phase 20-AUDIT-HEALTH.
 *
 * Provides a single, cross-module audit log viewer over the
 * `user_audit_log` table. While each master-data controller exposes its own
 * per-module `audit()` method (via BaseMasterDataController), this controller
 * aggregates ALL audit entries across ALL modules and supports:
 *
 *   - Filtering by table, action, user, date range, record_id, free-text search
 *   - CSV export of the filtered result set (RFC 4180 + UTF-8 BOM)
 *   - A detail view showing the full JSONB diff (old vs new) for a single entry
 *
 * Routes (admin-only):
 *   GET  /admin/audit           → index
 *   GET  /admin/audit/export    → CSV export
 *   GET  /admin/audit/{id}      → detail view
 *
 * The audit log schema (from database/sql/06_payment_and_misc.sql):
 *   user_audit_log (
 *     id, user_id, action, target_user_id, branch_id,
 *     details jsonb,    -- { table, record_id, old, new }
 *     ip_address, user_agent, created_at
 *   )
 */
class GlobalAuditController extends Controller
{
    use WritesExportAuditLog;

    /**
     * Canonical list of master-data tables that the AuditableMasterData trait
     * writes audit entries for. Used to populate the table filter dropdown.
     */
    private const AUDITED_TABLES = [
        'branches',
        'warehouses',
        'products',
        'product_categories',
        'product_groups',
        'customers',
        'suppliers',
        'employees',
        'banks',
        'ledgers',
        'users',
    ];

    /**
     * Canonical list of master-data audit actions written by the trait.
     * Used to populate the action filter dropdown.
     */
    private const AUDIT_ACTIONS = [
        'master_data_created',
        'master_data_updated',
        'master_data_deleted',
        'master_data_restored',
    ];

    /**
     * Index — paginated, filterable list of all audit entries.
     */
    public function index(GlobalAuditLogRequest $request)
    {
        $filters = $this->parseFilters($request);
        $query = $this->buildQuery($filters);

        $auditLogs = $query
            ->orderBy('ual.created_at', 'desc')
            ->orderBy('ual.id', 'desc')
            ->paginate(50)
            ->withQueryString();

        // Build the user dropdown (users that have produced audit entries).
        $users = DB::table('user_audit_log as ual')
            ->leftJoin('users as u', 'u.id', '=', 'ual.user_id')
            ->leftJoin('employees as e', 'e.id', '=', 'u.employee_id')
            ->whereNotNull('ual.user_id')
            ->select('ual.user_id', DB::raw('COALESCE(e.name, u.username) as name'))
            ->distinct()
            ->orderBy('name')
            ->get()
            ->pluck('name', 'user_id');

        return view('admin.audit.index', [
            'title'        => 'Global audit log',
            'auditLogs'    => $auditLogs,
            'filters'      => $filters,
            'tables'       => self::AUDITED_TABLES,
            'actions'      => self::AUDIT_ACTIONS,
            'users'        => $users,
        ]);
    }

    /**
     * Export the filtered audit log as a streamed CSV download.
     *
     * Produces RFC 4180-compliant CSV with a UTF-8 BOM (so Excel renders
     * UTF-8 characters correctly). Columns:
     *   ID, Timestamp, User ID, Performer, Action, Table, Record ID,
     *   IP Address, User Agent, Summary
     *
     * REPORTS-AUDIT-4 (G-150 / csv-export.md G11): refactored to delegate
     * to CsvExporter::exportFromRows(). BOM + Content-Type + RFC 4180
     * escaping now handled by the canonical service. Column order and
     * column labels preserved exactly. Writes an export_audit_log row.
     * The chunked DB cursor is consumed by the buildAuditCsvRows()
     * generator (also memory-bounded via chunk(500)).
     */
    public function export(GlobalAuditLogRequest $request): StreamedResponse
    {
        $filters = $this->parseFilters($request);
        $query = $this->buildQuery($filters)
            ->orderBy('ual.created_at', 'desc')
            ->orderBy('ual.id', 'desc');

        $headerRow = [
            'ID', 'Timestamp', 'User ID', 'Performer',
            'Action', 'Table', 'Record ID',
            'IP Address', 'User Agent', 'Summary',
        ];

        $rowGenerator = $this->buildAuditCsvRows($query);

        $filename = 'global_audit_export_' . now()->format('Ymd_His');

        // Audit log: row count unknown (chunked cursor stream — we do
        // not pre-count). Pass 0; the audit row records that an export
        // happened, with the filter context.
        $this->logExport('global_audit', $filters, rowCount: 0, byteSize: 0);

        return CsvExporter::exportFromRows($filename, $headerRow, $rowGenerator);
    }

    /**
     * Build the row generator for the global-audit CSV export.
     *
     * Extracted as a private method so the lint checker can validate the
     * export() method body (the linter cannot parse `yield` inside an
     * inline closure expression).
     *
     * Uses cursor() (LazyCollection) so each row is yielded one at a time
     * — PHP's `yield` keyword only works at the top level of a generator
     * function, NOT inside a nested closure (the chunk() callback would
     * swallow the yields). cursor() internally uses a single chunked
     * cursor + streams rows on iteration, so memory stays bounded for
     * very large audit-log exports (the table grows monotonically with
     * every master-data write).
     *
     * @param  \Illuminate\Database\Query\Builder $query
     * @return \Generator<int, array<int,mixed>>
     */
    private function buildAuditCsvRows($query): \Generator
    {
        foreach ($query->cursor() as $row) {
            $details = $this->decodeDetails($row->details);
            $summary = $this->summarize($details);
            yield [
                $row->id,
                optional($row->created_at)->format('Y-m-d H:i:s'),
                $row->user_id,
                $row->performed_by_name ?? ('#' . ($row->user_id ?? 0)),
                $row->action,
                $details['table'] ?? '',
                $details['record_id'] ?? '',
                $row->ip_address ?? '',
                $row->user_agent ?? '',
                $summary,
            ];
        }
    }

    /**
     * Show — single audit entry detail with pretty-printed JSON diff.
     */
    public function show(int $id)
    {
        $entry = DB::table('user_audit_log as ual')
            ->leftJoin('users as u', 'u.id', '=', 'ual.user_id')
            ->leftJoin('employees as e', 'e.id', '=', 'u.employee_id')
            ->where('ual.id', $id)
            ->select(
                'ual.id',
                'ual.user_id',
                'ual.action',
                'ual.target_user_id',
                'ual.branch_id',
                'ual.details',
                'ual.ip_address',
                'ual.user_agent',
                'ual.created_at',
                'u.username',
                'e.name as performed_by_name'
            )
            ->first();

        abort_unless($entry, 404, 'Audit entry not found.');

        $details = $this->decodeDetails($entry->details);
        $old = is_array($details['old'] ?? null) ? $details['old'] : [];
        $new = is_array($details['new'] ?? null) ? $details['new'] : [];

        // Build a unified diff: every field that appears in either old or new,
        // with its old value (if any) and new value (if any).
        $diff = $this->buildDiff($old, $new);

        return view('admin.audit.show', [
            'title'  => 'Audit entry #' . $entry->id,
            'entry'  => $entry,
            'details' => $details,
            'diff'   => $diff,
        ]);
    }

    // ====================================================================
    // HELPERS
    // ====================================================================

    /**
     * Parse + sanitize the filter inputs from the request.
     */
    private function parseFilters(Request $request): array
    {
        return [
            'table'      => trim((string) $request->input('table', '')),
            'action'     => trim((string) $request->input('action', '')),
            'user_id'    => $request->filled('user_id') ? (int) $request->input('user_id') : null,
            'from'       => trim((string) $request->input('from', '')),
            'to'         => trim((string) $request->input('to', '')),
            'record_id'  => trim((string) $request->input('record_id', '')),
            'search'     => trim((string) $request->input('search', '')),
        ];
    }

    /**
     * Build the base audit-log query with all requested filters applied.
     * Joins users → employees for the performer name (mirrors
     * BaseMasterDataController::audit()).
     */
    private function buildQuery(array $filters)
    {
        $query = DB::table('user_audit_log as ual')
            ->leftJoin('users as u', 'u.id', '=', 'ual.user_id')
            ->leftJoin('employees as e', 'e.id', '=', 'u.employee_id')
            ->select(
                'ual.id',
                'ual.user_id',
                'ual.action',
                'ual.target_user_id',
                'ual.branch_id',
                'ual.details',
                'ual.ip_address',
                'ual.user_agent',
                'ual.created_at',
                'u.username',
                'e.name as performed_by_name',
                DB::raw("ual.details::jsonb->>'table' as target_table"),
                DB::raw("ual.details::jsonb->>'record_id' as target_id")
            );

        // Restrict to master_data actions only by default — these are the
        // actions the AuditableMasterData trait writes. This keeps the global
        // viewer focused on master-data changes (consistent with the per-module
        // audit views). Login/logout/password events stay in the user_audit_log
        // table but are surfaced via the SystemHealth dashboard's "recent
        // activity" panel.
        $query->where('ual.action', 'like', 'master_data_%');

        if ($filters['table'] !== '') {
            $query->whereRaw("ual.details::jsonb->>'table' = ?", [$filters['table']]);
        }

        if ($filters['action'] !== '') {
            $query->where('ual.action', $filters['action']);
        }

        if ($filters['user_id'] !== null) {
            $query->where('ual.user_id', $filters['user_id']);
        }

        if ($filters['from'] !== '') {
            $query->where('ual.created_at', '>=', $filters['from'] . ' 00:00:00');
        }

        if ($filters['to'] !== '') {
            $query->where('ual.created_at', '<=', $filters['to'] . ' 23:59:59');
        }

        if ($filters['record_id'] !== '') {
            $query->whereRaw("ual.details::jsonb->>'record_id' = ?", [$filters['record_id']]);
        }

        if ($filters['search'] !== '') {
            // Cast details to text and ILIKE-search — Postgres JSONB text
            // representation lets us match any nested key or value.
            $query->whereRaw("ual.details::text ILIKE ?", ['%' . $filters['search'] . '%']);
        }

        return $query;
    }

    /**
     * Decode the JSONB details column to an associative array (or [] on failure).
     */
    private function decodeDetails($details): array
    {
        if (is_array($details)) {
            return $details;
        }
        if (is_string($details) && $details !== '') {
            $decoded = json_decode($details, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    /**
     * Build a short human-readable summary of an audit entry's details
     * (used as the last CSV column — quick scan of what changed).
     */
    private function summarize(array $details): string
    {
        $table     = $details['table'] ?? '?';
        $recordId  = $details['record_id'] ?? '?';
        $old = $details['old'] ?? null;
        $new = $details['new'] ?? null;

        if (is_array($new) && !empty($new)) {
            $parts = [];
            foreach (array_slice($new, 0, 4, true) as $field => $value) {
                $parts[] = $field . '=' . self::scalarToString($value);
            }
            return "{$table}#{$recordId} new: " . implode(', ', $parts);
        }

        if (is_array($old) && !empty($old)) {
            $parts = [];
            foreach (array_slice($old, 0, 4, true) as $field => $value) {
                $parts[] = $field . '=' . self::scalarToString($value);
            }
            return "{$table}#{$recordId} old: " . implode(', ', $parts);
        }

        return "{$table}#{$recordId}";
    }

    /**
     * Build a unified field-by-field diff for the show view.
     *
     * Returns a list of associative arrays with keys:
     *   field, has_old, has_new, old (string), new (string), state
     * where `state` is one of: 'added', 'removed', 'changed', 'unchanged'.
     */
    private function buildDiff(array $old, array $new): array
    {
        $fields = array_unique(array_merge(array_keys($old), array_keys($new)));
        sort($fields);

        $diff = [];
        foreach ($fields as $field) {
            // Skip Eloquent-managed timestamp/deleted_by columns for clarity.
            if (in_array($field, ['created_at', 'updated_at', 'deleted_at'], true)) {
                continue;
            }

            $hasOld = array_key_exists($field, $old);
            $hasNew = array_key_exists($field, $new);
            $oldVal = $hasOld ? self::scalarToString($old[$field]) : '';
            $newVal = $hasNew ? self::scalarToString($new[$field]) : '';

            $state = 'unchanged';
            if ($hasOld && !$hasNew) {
                $state = 'removed';
            } elseif (!$hasOld && $hasNew) {
                $state = 'added';
            } elseif ($oldVal !== $newVal) {
                $state = 'changed';
            }

            $diff[] = [
                'field'   => $field,
                'has_old' => $hasOld,
                'has_new' => $hasNew,
                'old'     => $oldVal,
                'new'     => $newVal,
                'state'   => $state,
            ];
        }

        return $diff;
    }

    /**
     * Convert a scalar/array/null value to a display string.
     */
    private static function scalarToString($value): string
    {
        if ($value === null) {
            return '(null)';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return (string) $value;
    }
}
