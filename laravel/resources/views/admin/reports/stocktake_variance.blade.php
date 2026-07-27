@extends('layouts.admin')

@php
    // Phase 6 (Stock Take plan): full status badge map covering every
    // stock_take_sessions.status value (draft / counting / submitted /
    // approved / posted / cancelled / reversed). The previous stub only
    // knew about 4 of these and rendered 'posted' as a grey default.
    $statusBadge = [
        'draft'      => 'secondary',
        'counting'   => 'primary',
        'submitted'  => 'warning',
        'approved'   => 'info',
        'posted'     => 'success',
        'cancelled'  => 'danger',
        'reversed'   => 'dark',
    ];
    $fmt = fn($v) => number_format((float) $v, 2);
    $fmtQty = fn($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
@endphp

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-table me-2 text-primary"></i> Stock Take Variance</h2>
            <p class="text-muted mb-0 small">Line-level count vs system — every variance where physical ≠ system. {{ \Carbon\Carbon::parse($meta['from_date'])->format('d M Y') }} → {{ \Carbon\Carbon::parse($meta['to_date'])->format('d M Y') }}.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.reports.stocktakeWeekly') }}" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-chart-line me-1"></i> Weekly
            </a>
            <a href="{{ route('admin.reports.stocktakeVarianceExport', request()->query()) }}" class="btn btn-outline-success btn-sm">
                <i class="fas fa-file-csv me-1"></i> CSV
            </a>
            <a href="{{ route('admin.reports.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Reports
            </a>
        </div>
    </div>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('admin.reports.stocktakeVariance') }}" class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-2">
                    <label class="form-label small mb-1">From date</label>
                    <input type="date" name="from_date" class="form-control form-control-sm"
                           value="{{ old('from_date', $filters['from'] ?? $meta['from_date'] ?? '') }}">
                </div>
                <div class="col-sm-6 col-md-2">
                    <label class="form-label small mb-1">To date</label>
                    <input type="date" name="to_date" class="form-control form-control-sm"
                           value="{{ old('to_date', $filters['to'] ?? $meta['to_date'] ?? '') }}">
                </div>
                @if (!empty($is_admin))
                <div class="col-sm-6 col-md-2">
                    <label class="form-label small mb-1">Branch</label>
                    <select name="branch_id" class="form-select form-select-sm">
                        <option value="">— All —</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" @selected((int)($filters['branch_id'] ?? 0) === (int)$b->id)>{{ $b->branch_name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                <div class="col-sm-6 col-md-2">
                    <label class="form-label small mb-1">Session</label>
                    <select name="session_id" class="form-select form-select-sm">
                        <option value="">— All —</option>
                        @foreach ($sessions as $s)
                            <option value="{{ $s->id }}" @selected((int)($filters['session_id'] ?? 0) === (int)$s->id)>
                                {{ $s->session_code }} · {{ \Carbon\Carbon::parse($s->session_date)->format('d M') }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-md-2">
                    <label class="form-label small mb-1">Warehouse</label>
                    <select name="warehouse_id" class="form-select form-select-sm">
                        <option value="">— All —</option>
                        @foreach ($warehouses as $w)
                            <option value="{{ $w->id }}" @selected((int)($filters['warehouse_id'] ?? 0) === (int)$w->id)>{{ $w->warehouse_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-1">
                    <button type="submit" class="btn btn-primary btn-sm flex-fill">
                        <i class="fas fa-play me-1"></i> Run
                    </button>
                    <a href="{{ route('admin.reports.stocktakeVariance') }}" class="btn btn-outline-secondary btn-sm" title="Reset">
                        <i class="fas fa-rotate-left"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    {{-- Summary cards --}}
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 px-3">
                    <div class="text-muted small text-uppercase">Variance lines</div>
                    <div class="fs-4 fw-semibold">{{ number_format($summary['total_items']) }}</div>
                    <div class="small text-muted">
                        <span class="text-success">+{{ $summary['gain_lines'] }} gain</span> ·
                        <span class="text-danger">−{{ $summary['loss_lines'] }} loss</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 px-3">
                    <div class="text-muted small text-uppercase">Net variance qty</div>
                    <div class="fs-4 fw-semibold {{ $summary['total_variance'] >= 0 ? 'text-success' : 'text-danger' }}">
                        {{ $summary['total_variance'] >= 0 ? '+' : '' }}{{ $fmtQty($summary['total_variance']) }}
                    </div>
                    <div class="small text-muted">physical − system</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 px-3">
                    <div class="text-muted small text-uppercase">Net value diff</div>
                    <div class="fs-4 fw-semibold {{ $summary['total_value_diff'] >= 0 ? 'text-success' : 'text-danger' }}">
                        {{ $summary['total_value_diff'] >= 0 ? '+' : '' }}{{ $fmt($summary['total_value_diff']) }}
                    </div>
                    <div class="small text-muted">Σ variance × rate</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 px-3">
                    <div class="text-muted small text-uppercase">Gain / Loss value</div>
                    <div class="fs-5 fw-semibold">
                        <span class="text-success">+{{ $fmt($summary['gain_value']) }}</span>
                        <span class="text-muted">·</span>
                        <span class="text-danger">−{{ $fmt($summary['loss_value']) }}</span>
                    </div>
                    <div class="small text-muted">gross gain &amp; loss</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Variance lines table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Session</th>
                            <th>Date</th>
                            <th>Branch</th>
                            <th>Warehouse</th>
                            <th>Product</th>
                            <th class="text-end">System</th>
                            <th class="text-end">Physical</th>
                            <th class="text-end">Diff</th>
                            <th class="text-end">System Rate</th>
                            <th class="text-end">Post Rate</th>
                            <th class="text-end">Value Diff</th>
                            <th class="text-end">Revaluation</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">GL</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($data as $r)
                            @php
                                $qty = (float) $r->variance_qty;
                                $val = (float) $r->value_diff;
                                $reval = (float) ($r->revaluation_amount ?? 0);
                                $effectiveStatus = !empty($r->is_reversed) ? 'reversed' : $r->session_status;
                            @endphp
                            <tr>
                                <td>
                                    <a href="{{ route('admin.stock-take.show', $r->session_id) }}" class="text-decoration-none">
                                        <code>{{ $r->session_code }}</code>
                                    </a>
                                </td>
                                <td class="text-nowrap small">{{ \Carbon\Carbon::parse($r->session_date)->format('d M Y') }}</td>
                                <td class="small">{{ $r->branch_name ?? '—' }}</td>
                                <td class="small">{{ $r->warehouse_name ?? '—' }}</td>
                                <td class="small">
                                    <div class="fw-semibold">{{ $r->product_code }}</div>
                                    <div class="text-muted" style="max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $r->product_name }}</div>
                                </td>
                                <td class="text-end font-monospace small">{{ $fmtQty($r->system_qty) }}</td>
                                <td class="text-end font-monospace small">{{ $fmtQty($r->physical_qty) }}</td>
                                <td class="text-end font-monospace small fw-semibold {{ $qty >= 0 ? 'text-success' : 'text-danger' }}">
                                    {{ $qty >= 0 ? '+' : '' }}{{ $fmtQty($qty) }}
                                </td>
                                <td class="text-end font-monospace small">{{ $fmt($r->system_rate) }}</td>
                                <td class="text-end font-monospace small fw-semibold {{ $r->post_rate && $r->system_rate && abs((float)$r->post_rate - (float)$r->system_rate) > 0.01 ? 'text-warning' : '' }}">{{ $fmt($r->post_rate) }}</td>
                                <td class="text-end font-monospace small fw-semibold {{ $val >= 0 ? 'text-success' : 'text-danger' }}">
                                    {{ $val >= 0 ? '+' : '' }}{{ $fmt($val) }}
                                </td>
                                <td class="text-end font-monospace small fw-semibold {{ abs($reval) >= 0.01 ? ($reval >= 0 ? 'text-success' : 'text-danger') : 'text-muted' }}">
                                    {{ abs($reval) >= 0.01 ? ($reval >= 0 ? '+' : '') . $fmt($reval) : '—' }}
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-{{ $statusBadge[$effectiveStatus] ?? 'secondary' }}-subtle text-{{ $statusBadge[$effectiveStatus] ?? 'secondary' }}">
                                        {{ ucfirst(str_replace('_', ' ', $effectiveStatus)) }}
                                    </span>
                                    @if (!empty($r->is_applied))
                                        <span class="badge bg-light text-dark" title="Applied to stock + GL"><i class="fas fa-check"></i></span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if (!empty($r->journal_entry_id))
                                        <button type="button" class="btn btn-link btn-sm p-0 st-gl-drilldown" data-session-id="{{ $r->session_id }}" title="View journal entry">
                                            <i class="fas fa-book-open text-primary"></i>
                                        </button>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="14" class="text-muted text-center py-4">No variance lines in the selected period. Adjust the filters and try again.</td></tr>
                        @endforelse
                    </tbody>
                    @if (!empty($data->total()))
                    <tfoot class="table-light">
                        <tr class="fw-semibold">
                            <td colspan="7" class="text-end">Totals ({{ number_format($data->total()) }} lines)</td>
                            <td class="text-end font-monospace {{ $summary['total_variance'] >= 0 ? 'text-success' : 'text-danger' }}">{{ $summary['total_variance'] >= 0 ? '+' : '' }}{{ $fmtQty($summary['total_variance']) }}</td>
                            <td colspan="2"></td>
                            <td class="text-end font-monospace {{ $summary['total_value_diff'] >= 0 ? 'text-success' : 'text-danger' }}">{{ $summary['total_value_diff'] >= 0 ? '+' : '' }}{{ $fmt($summary['total_value_diff']) }}</td>
                            <td class="text-end font-monospace {{ ($summary['total_revaluation'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">{{ ($summary['total_revaluation'] ?? 0) != 0 ? (($summary['total_revaluation'] >= 0 ? '+' : '') . $fmt($summary['total_revaluation'])) : '—' }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>
        @if (method_exists($data, 'hasPages') && $data->hasPages())
            <div class="card-footer bg-white py-2">{{ $data->links() }}</div>
        @endif
    </div>
</div>

{{-- GL drill-down modal --}}
<div class="modal fade" id="stGlModal" tabindex="-1" aria-labelledby="stGlModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="stGlModalLabel"><i class="fas fa-book-open me-2 text-primary"></i> Journal Entry — <span id="stGlSessionCode" class="font-monospace"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="stGlBody">
                    <div class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2"></span>Loading journal entry…</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const modalEl = document.getElementById('stGlModal');
    const bodyEl  = document.getElementById('stGlBody');
    const codeEl  = document.getElementById('stGlSessionCode');
    if (!modalEl || !bodyEl) return;

    const fmt = (v) => {
        const n = Number(v || 0);
        return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    const escapeHtml = (s) => String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.st-gl-drilldown');
        if (!btn) return;

        const sessionId = btn.getAttribute('data-session-id');
        if (!sessionId) return;

        codeEl.textContent = '…';
        bodyEl.innerHTML = '<div class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2"></span>Loading journal entry…</div>';

        const bsModal = (window.bootstrap ? bootstrap.Modal.getOrCreateInstance(modalEl) : new Modal(modalEl));
        bsModal.show();

        fetch("{{ route('admin.reports.stocktakeVarianceJournal', ['session' => '__SID__']) }}".replace('__SID__', encodeURIComponent(sessionId)), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            const session = data.session || {};
            codeEl.textContent = session.session_code || ('#' + sessionId);

            if (!data.entry) {
                bodyEl.innerHTML = '<div class="alert alert-info mb-0"><i class="fas fa-info-circle me-2"></i>This session has no posted journal entry yet (status: <strong>' + escapeHtml(session.status || '—') + '</strong>).</div>';
                return;
            }

            const entry = data.entry;
            const lines = data.lines || [];
            const totalDr = lines.reduce((s, l) => s + Number(l.debit || 0), 0);
            const totalCr = lines.reduce((s, l) => s + Number(l.credit || 0), 0);

            const header = `
                <dl class="row mb-3 small">
                    <dt class="col-sm-3">Entry No</dt><dd class="col-sm-9 font-monospace">${escapeHtml(entry.entry_no)}</dd>
                    <dt class="col-sm-3">Date</dt><dd class="col-sm-9">${escapeHtml(entry.entry_date)}</dd>
                    <dt class="col-sm-3">Status</dt><dd class="col-sm-9">${entry.is_reversed ? '<span class="badge bg-dark-subtle text-dark">Reversed</span>' : '<span class="badge bg-success-subtle text-success">Posted</span>'}</dd>
                    <dt class="col-sm-3">Source</dt><dd class="col-sm-9"><span class="badge bg-secondary-subtle text-secondary">${escapeHtml(entry.source || 'stock_take')}</span></dd>
                    <dt class="col-sm-3">Description</dt><dd class="col-sm-9">${escapeHtml(entry.description || '')}</dd>
                </dl>`;

            let rows = '';
            lines.forEach(l => {
                rows += `
                    <tr>
                        <td class="font-monospace small">${escapeHtml(l.ledger_code || '')}</td>
                        <td>${escapeHtml(l.ledger_name || '')}</td>
                        <td><span class="badge bg-light text-dark border">${escapeHtml(l.account_type || '')}</span></td>
                        <td class="text-end font-monospace ${Number(l.debit) > 0 ? 'text-success' : 'text-muted'}">${Number(l.debit) > 0 ? fmt(l.debit) : '—'}</td>
                        <td class="text-end font-monospace ${Number(l.credit) > 0 ? 'text-danger' : 'text-muted'}">${Number(l.credit) > 0 ? fmt(l.credit) : '—'}</td>
                        <td class="small text-muted">${escapeHtml(l.memo || '')}</td>
                    </tr>`;
            });

            const table = `
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Code</th><th>Ledger</th><th>Type</th>
                                <th class="text-end">Debit</th><th class="text-end">Credit</th><th>Memo</th>
                            </tr>
                        </thead>
                        <tbody>${rows}</tbody>
                        <tfoot class="table-light fw-semibold">
                            <tr>
                                <td colspan="3" class="text-end">Total</td>
                                <td class="text-end font-monospace">${fmt(totalDr)}</td>
                                <td class="text-end font-monospace">${fmt(totalCr)}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>`;

            bodyEl.innerHTML = header + table;
        })
        .catch(() => {
            bodyEl.innerHTML = '<div class="alert alert-danger mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Failed to load the journal entry. Please try again.</div>';
        });
    });
})();
</script>
@endpush
@endsection
