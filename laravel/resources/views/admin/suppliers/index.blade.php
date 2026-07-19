@extends('layouts.admin')

@section('content')

@push('css')
<style>
    .md-hero {
        background: linear-gradient(135deg, #b45309 0%, #d97706 100%);
        color: #fff; border-radius: .75rem; padding: 1.25rem 1.5rem;
        display: flex; justify-content: space-between; flex-wrap: wrap;
        gap: 1rem; align-items: center; margin-bottom: 1rem;
    }
    .md-hero h1 { font-size: 1.5rem; margin: 0 0 .25rem; font-weight: 700; }
    .md-hero p  { margin: 0; opacity: .9; font-size: .9rem; }
    .md-hero .hero-badge {
        display: inline-flex; align-items: center; gap: .35rem;
        background: rgba(255,255,255,.15); padding: .25rem .6rem;
        border-radius: 1rem; font-size: .8rem; margin-top: .35rem;
    }
    .md-hero-actions { display: flex; gap: .5rem; flex-wrap: wrap; }
    .md-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: .75rem; margin-bottom: 1rem; }
    .md-stat-card {
        background: #fff; border: 1px solid #e7eaf0; border-radius: .65rem;
        padding: .9rem 1rem; display: flex; gap: .75rem; align-items: center;
        box-shadow: 0 1px 2px rgba(15,23,42,.04);
    }
    .md-stat-icon { width: 42px; height: 42px; border-radius: .5rem; display: grid; place-items: center; color: #fff; font-size: 1.05rem; }
    .md-stat-icon.amber  { background: #d97706; }
    .md-stat-icon.slate  { background: #64748b; }
    .md-stat-icon.indigo { background: #4f46e5; }
    .md-stat-icon.teal   { background: #2c8a6e; }
    .md-stat-value { font-size: 1.15rem; font-weight: 700; line-height: 1.1; }
    .md-stat-label { color: #6b7280; font-size: .8rem; }
    .md-panel { background: #fff; border: 1px solid #e7eaf0; border-radius: .65rem; box-shadow: 0 1px 2px rgba(15,23,42,.04); overflow: hidden; }
    .md-panel-filters { padding: .9rem 1rem; border-bottom: 1px solid #eef2f6; background: #fafbfd; }
    .md-panel-body { padding: 0; }
    .md-table { width: 100% !important; }
    .md-table th { font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; color: #64748b; padding: .75rem 1rem; }
    .md-table td { padding: .65rem 1rem; vertical-align: middle; }
    .code-pill { display: inline-block; background: #fef3c7; color: #92400e; padding: .15rem .55rem; border-radius: 1rem; font-size: .75rem; font-weight: 600; }
    .status-pill { display: inline-flex; align-items: center; gap: .35rem; padding: .15rem .55rem; border-radius: 1rem; font-size: .75rem; font-weight: 600; }
    .status-pill.active   { background: #dcfce7; color: #166534; }
    .status-pill.inactive { background: #fee2e2; color: #991b1b; }
    .status-pill .dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
    .row-action { display: inline-flex; gap: .25rem; }
    .row-action a, .row-action button {
        display: inline-grid; place-items: center; width: 30px; height: 30px;
        border-radius: .4rem; border: 1px solid #e2e8f0; background: #fff; color: #475569;
        text-decoration: none; cursor: pointer; transition: all .15s;
    }
    .row-action a:hover, .row-action button:hover { background: #f1f5f9; color: #0f172a; }
    .row-action .view    { color: #0ea5e9; }
    .row-action .edit    { color: #6366f1; }
    .row-action .toggle  { color: #ef4444; }
    .row-action .restore { color: #16a34a; }
</style>
@endpush

<div class="md-hero">
    <div>
        <h1>
            <i class="fas fa-truck me-2"></i>
            {{ $showDeleted ? 'Inactive suppliers' : 'Supplier directory' }}
        </h1>
        <p>
            @if ($showDeleted)
                Restore vendors when needed — deactivation stays blocked while AP or purchase history exists.
            @else
                Master data for purchase orders, GRN/receive, payments, and supplier_ledger AP.
            @endif
        </p>
        <span class="hero-badge"><i class="fas fa-book"></i> supplier_ledger · Tk</span>
    </div>
    <div class="md-hero-actions">
        @if ($showDeleted)
            <a href="{{ route("{$routePrefix}.index") }}" class="btn btn-light btn-sm">
                <i class="fas fa-truck me-1"></i> Active list
            </a>
        @else
            <a href="{{ route("{$routePrefix}.index", ['deleted' => 1]) }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-box-archive me-1"></i> Inactive ({{ $stats['inactive'] ?? 0 }})
            </a>
        @endif
        <a href="{{ route("{$routePrefix}.audit") }}" class="btn btn-outline-light btn-sm">
            <i class="fas fa-clock-rotate-left me-1"></i> Audit
        </a>
        <a href="{{ route("{$routePrefix}.export") }}" class="btn btn-outline-light btn-sm" title="Download all suppliers as a CSV file">
            <i class="fas fa-file-csv me-1"></i> Export CSV
        </a>
        <a href="{{ route("{$routePrefix}.create") }}" class="btn btn-light btn-sm">
            <i class="fas fa-plus me-1"></i> New supplier
        </a>
    </div>
</div>

@if (! $showDeleted)
<div class="md-stats">
    <div class="md-stat-card">
        <div class="md-stat-icon amber"><i class="fas fa-truck"></i></div>
        <div>
            <div class="md-stat-value">{{ $stats['active'] ?? 0 }}</div>
            <div class="md-stat-label">Active vendors</div>
        </div>
    </div>
    <div class="md-stat-card">
        <div class="md-stat-icon slate"><i class="fas fa-box-archive"></i></div>
        <div>
            <div class="md-stat-value">{{ $stats['inactive'] ?? 0 }}</div>
            <div class="md-stat-label">Inactive</div>
        </div>
    </div>
    <div class="md-stat-card">
        <div class="md-stat-icon indigo"><i class="fas fa-truck-fast"></i></div>
        <div>
            <div class="md-stat-value">{{ $stats['total'] ?? 0 }}</div>
            <div class="md-stat-label">Total vendors</div>
        </div>
    </div>
</div>
@endif

<div class="md-panel">
    @if (! $showDeleted)
    <div class="md-panel-filters">
        <div class="row g-2 align-items-end">
            <div class="col-sm-4 col-md-3">
                <label class="form-label small mb-1">Search</label>
                <input type="text" id="globalSearch" class="form-control form-control-sm" placeholder="Code, name, mobile, phone…">
            </div>
            <div class="col-sm-auto">
                <button type="button" id="clearFilters" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-rotate-left me-1"></i> Reset
                </button>
            </div>
        </div>
    </div>
    @endif

    <div class="md-panel-body">
        <div class="table-responsive">
            <table id="supplierTable" class="table table-borderless align-middle md-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Supplier name</th>
                        <th class="d-none d-lg-table-cell">Mobile</th>
                        <th class="d-none d-md-table-cell">Branch</th>
                        <th class="d-none d-xl-table-cell">Contact person</th>
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const BASE = "{{ route("{$routePrefix}.index") }}";
    const SHOW_DELETED = {{ $showDeleted ? 'true' : 'false' }};
    const SHOW_ROUTE_TPL = "{{ route("{$routePrefix}.show", '__ID__') }}";
    const EDIT_ROUTE_TPL = "{{ route("{$routePrefix}.edit", '__ID__') }}";

    function esc(s) {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function statusPill(v) {
        const on = Number(v) === 1 || v === true;
        return on
            ? '<span class="status-pill active"><span class="dot"></span> Active</span>'
            : '<span class="status-pill inactive"><span class="dot"></span> Inactive</span>';
    }

    function rowActions(id) {
        const showUrl = SHOW_ROUTE_TPL.replace('__ID__', id);
        const editUrl = EDIT_ROUTE_TPL.replace('__ID__', id);
        let html = '<div class="row-action">';
        html += '<a href="' + showUrl + '" class="view" title="View"><i class="fas fa-circle-info"></i></a>';
        html += '<a href="' + editUrl + '" class="edit" title="Edit"><i class="fas fa-pen"></i></a>';
        const method = SHOW_DELETED ? 'PATCH' : 'DELETE';
        const confirmMsg = SHOW_DELETED ? 'Restore this supplier?' : 'Deactivate this supplier?';
        html += '<form method="POST" action="' + showUrl + (SHOW_DELETED ? '/restore' : '') + '" style="display:inline">'
             +  '<input type="hidden" name="_token" value="' + window.CSRF_TOKEN + '">'
             +  '<input type="hidden" name="_method" value="' + method + '">'
             +  '<button type="submit" class="' + (SHOW_DELETED ? 'restore' : 'toggle') + '" title="' + (SHOW_DELETED ? 'Restore' : 'Deactivate') + '" onclick="return confirm(\'' + confirmMsg + '\')">'
             +  '<i class="fas ' + (SHOW_DELETED ? 'fa-rotate-left' : 'fa-power-off') + '"></i></button>'
             +  '</form>';
        html += '</div>';
        return html;
    }

    const table = $('#supplierTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: BASE + (SHOW_DELETED ? '?deleted=1' : ''),
            data: function (d) {
                const g = $('#globalSearch').val();
                if (g) d.search = { value: g, regex: false };
                if (SHOW_DELETED) d.deleted = 1;
            }
        },
        pageLength: 25,
        order: [[1, 'asc']],
        dom: '<"row mb-2"<"col-sm-6"l><"col-sm-6 text-end"f>>rtip',
        language: {
            processing: '<i class="fas fa-circle-notch fa-spin me-1"></i> Loading suppliers…',
            emptyTable: 'No suppliers found',
            zeroRecords: 'No matching suppliers'
        },
        columns: [
            { data: 'supplier_code', render: function (d) {
                return '<span class="code-pill">' + esc(d) + '</span>';
            }},
            { data: 'supplier_name', render: function (d, t, row) {
                const code = row.supplier_code ? '<div class="small text-muted">' + esc(row.supplier_code) + '</div>' : '';
                return '<a href="' + SHOW_ROUTE_TPL.replace('__ID__', row.id) + '" class="text-decoration-none fw-semibold text-reset">'
                    + esc(d || '—') + '</a>' + code;
            }},
            { data: 'mobile', className: 'd-none d-lg-table-cell', render: function (d) {
                return d ? '<a href="tel:' + esc(d) + '" class="text-decoration-none"><i class="fas fa-phone me-1 text-muted"></i>' + esc(d) + '</a>'
                         : '<span class="text-muted">—</span>';
            }},
            { data: 'branch.branch_name', className: 'd-none d-md-table-cell', defaultContent: '—', render: function (d) {
                return d ? esc(d) : '<span class="text-muted">—</span>';
            }},
            { data: 'contact_person', className: 'd-none d-xl-table-cell', defaultContent: '—', render: function (d) {
                return d ? '<span class="badge bg-light text-dark"><i class="fas fa-user me-1"></i>' + esc(d) + '</span>'
                         : '<span class="text-muted">—</span>';
            }},
            { data: 'is_active', render: function (d) { return statusPill(d); }},
            { data: 'id', orderable: false, className: 'text-center', render: function (d) { return rowActions(d); }}
        ]
    });

    $('#globalSearch').on('keyup', function () { table.search(this.value).draw(); });
    $('#clearFilters').on('click', function () { $('#globalSearch').val(''); table.search('').draw(); });
    window.supplierTable = table;
})();
</script>
@endpush

@endsection
