@php
    /**
     * Phase 6 — shared audit-log table partial.
     *
     * Inputs (passed from the per-module audit blade):
     *   $logs         — paginator of user_audit_log rows (joined with users + employees + branches)
     *   $module       — 'purchase_order' | 'purchase_receive' | 'purchase_return'
     *   $moduleLabel  — display string ("Purchase Order", etc.)
     *   $indexRoute   — back-link URL
     *   $filters      — request->only(['search', 'branch_id'])
     */

    $actionBadgeClass = function (string $action): string {
        if (str_ends_with($action, '_created'))   return 'bg-success';
        if (str_ends_with($action, '_updated'))   return 'bg-info text-dark';
        if (str_ends_with($action, '_sent'))      return 'bg-primary';
        if (str_ends_with($action, '_confirmed')) return 'bg-primary';
        if (str_ends_with($action, '_cancelled')) return 'bg-danger';
        if (str_ends_with($action, '_reversed'))  return 'bg-danger';
        return 'bg-secondary';
    };

    $performerLabel = function ($row): string {
        $empName = trim((string) ($row->employee_name ?? ''));
        $username = trim((string) ($row->username ?? ''));
        if ($empName !== '') {
            return $username !== '' ? "{$empName} ({$username})" : $empName;
        }
        if ($username !== '') return $username;
        if ((int) ($row->user_id ?? 0) > 0) return 'User #' . $row->user_id;
        return 'System';
    };

    $prettyDetails = function ($raw): string {
        if (empty($raw)) return '';
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($decoded)) return '';
        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    };
@endphp

<div class="purch-audit-log-app container-fluid py-2">
    <header class="purch-audit-log-hero d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h2 class="h4 mb-1">
                <i class="fas fa-history me-2 text-primary"></i>
                {{ $moduleLabel }} — Audit Log
            </h2>
            <p class="text-muted small mb-0">
                All user actions on {{ $moduleLabel }} documents, newest first. Source: <code>user_audit_log</code>.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ $indexRoute }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to {{ $moduleLabel }} list
            </a>
        </div>
    </header>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="" class="row g-2 align-items-end">
                <div class="col-sm-8 col-md-6">
                    <label class="form-label small mb-1">Search (action / user / employee)</label>
                    <input type="text" name="search" class="form-control form-control-sm"
                           value="{{ $filters['search'] ?? '' }}"
                           placeholder="e.g. cancelled, alice, john…">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-search me-1"></i> Filter
                    </button>
                </div>
                <div class="col-md-2">
                    <a href="{{ $indexRoute }}" class="btn btn-outline-secondary btn-sm w-100">
                        <i class="fas fa-times me-1"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    {{-- Logs table --}}
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 purch-audit-log-table">
                <thead class="table-light">
                    <tr>
                        <th style="width: 160px">Timestamp</th>
                        <th style="width: 200px">By</th>
                        <th style="width: 200px">Action</th>
                        <th style="width: 80px">Target</th>
                        <th>Details</th>
                        <th style="width: 130px">IP</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ \Carbon\Carbon::parse($log->logged_at)->format('Y-m-d H:i:s') }}</div>
                                @if (!empty($log->branch_name))
                                    <small class="text-muted">{{ $log->branch_name }}</small>
                                @endif
                            </td>
                            <td>{{ $performerLabel($log) }}</td>
                            <td>
                                <span class="badge {{ $actionBadgeClass($log->action) }} purch-audit-action-badge">
                                    {{ $log->action }}
                                </span>
                            </td>
                            <td>
                                @if ((int) $log->target_id > 0)
                                    <a href="{{ $indexRoute }}/{{ $log->target_id }}"
                                       class="font-monospace small" target="_blank" title="Open document">
                                        #{{ $log->target_id }}
                                    </a>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                @php($det = $prettyDetails($log->details))
                                @if ($det)
                                    <details class="purch-audit-details">
                                        <summary class="small text-muted">View details</summary>
                                        <pre class="small bg-light p-2 mt-1 mb-0 rounded">{{ $det }}</pre>
                                    </details>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                <span class="font-monospace small">{{ $log->ip_address ?? '—' }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">
                                <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                No audit entries found for {{ $moduleLabel }}.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($logs->hasPages())
            <div class="card-footer bg-white py-2">
                {{ $logs->links() }}
            </div>
        @endif
    </div>
</div>
