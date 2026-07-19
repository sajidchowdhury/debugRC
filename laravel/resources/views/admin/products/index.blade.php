@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white" style="background:linear-gradient(135deg,#0f766e,#14b8a6);">
        <div>
            <h1 class="h3 mb-1">
                <i class="fas fa-boxes-stacked me-2"></i>
                {{ $showDeleted ? 'Inactive products' : 'Product catalog' }}
            </h1>
            <p class="mb-0 opacity-75">Master SKUs for purchase, sales, godown, challan &amp; stock — price ranges via price history.</p>
        </div>
        <div class="d-flex flex-wrap gap-1">
            @if ($showDeleted)
                <a href="{{ route($routePrefix . '.index') }}" class="btn btn-light btn-sm">
                    <i class="fas fa-boxes me-1"></i> Active
                </a>
            @else
                <a href="{{ route($routePrefix . '.index', ['deleted' => 1]) }}" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-box-archive me-1"></i> Inactive ({{ $stats['inactive'] ?? 0 }})
                </a>
            @endif
            <a href="{{ route($routePrefix . '.audit') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clock-rotate-left me-1"></i> Audit
            </a>
            <a href="{{ route('admin.product-groups.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-globe me-1"></i> Groups
            </a>
            <a href="{{ route('admin.product-categories.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-tags me-1"></i> Categories
            </a>
            <a href="{{ route($routePrefix . '.export') }}" class="btn btn-outline-light btn-sm" title="Download all products as a CSV file">
                <i class="fas fa-file-csv me-1"></i> Export CSV
            </a>
            <a href="{{ route($routePrefix . '.create') }}" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New product
            </a>
        </div>
    </header>

    @if (!$showDeleted)
    {{-- Stats cards --}}
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-2">
                    <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:42px;height:42px;background:#0f766e;"><i class="fas fa-box"></i></span>
                    <div>
                        <div class="fs-5 fw-bold lh-1">{{ $stats['total'] ?? 0 }}</div>
                        <div class="text-muted small">Total SKUs</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-2">
                    <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:42px;height:42px;background:#10b981;"><i class="fas fa-circle-check"></i></span>
                    <div>
                        <div class="fs-5 fw-bold lh-1">{{ $stats['active'] ?? 0 }}</div>
                        <div class="text-muted small">Active</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-2">
                    <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:42px;height:42px;background:#64748b;"><i class="fas fa-moon"></i></span>
                    <div>
                        <div class="fs-5 fw-bold lh-1">{{ $stats['inactive'] ?? 0 }}</div>
                        <div class="text-muted small">Inactive</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-2">
                    <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:42px;height:42px;background:#f59e0b;"><i class="fas fa-triangle-exclamation"></i></span>
                    <div>
                        <div class="fs-5 fw-bold lh-1">{{ $stats['low_stock'] ?? 0 }}</div>
                        <div class="text-muted small">Low stock</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-2">
                    <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:42px;height:42px;background:#6366f1;"><i class="fas fa-tags"></i></span>
                    <div>
                        <div class="fs-5 fw-bold lh-1">{{ $stats['categories'] ?? 0 }}</div>
                        <div class="text-muted small">Categories</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-2">
                    <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:42px;height:42px;background:#a855f7;"><i class="fas fa-globe"></i></span>
                    <div>
                        <div class="fs-5 fw-bold lh-1">{{ $stats['groups'] ?? 0 }}</div>
                        <div class="text-muted small">Groups</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Panel: filters + table --}}
    <div class="card border-0 shadow-sm">
        @if (!$showDeleted)
        <div class="card-header bg-white py-2">
            <div class="row g-2 align-items-end">
                <div class="col-sm-4 col-md-3">
                    <label class="form-label small mb-1">Category</label>
                    <select id="filterCategory" class="form-select form-select-sm">
                        <option value="">All categories</option>
                        @foreach (\App\Models\ProductCategory::whereNull('deleted_at')->orderBy('category_name')->get() as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->category_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-4 col-md-3">
                    <label class="form-label small mb-1">Group</label>
                    <select id="filterGroup" class="form-select form-select-sm">
                        <option value="">All groups</option>
                        @foreach (\App\Models\ProductGroup::whereNull('deleted_at')->orderBy('sort_order')->get() as $grp)
                            <option value="{{ $grp->id }}">{{ $grp->group_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-4 col-md-2">
                    <label class="form-label small mb-1">Unit</label>
                    <select id="filterUnit" class="form-select form-select-sm">
                        <option value="">All units</option>
                        @foreach (['Pcs','Carton','KG','Bag','Dobe','Set'] as $u)
                            <option value="{{ $u }}">{{ $u }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-auto">
                    <button type="button" id="clearFilters" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-rotate-left me-1"></i> Reset
                    </button>
                </div>
            </div>
        </div>
        @endif

        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="productTable" class="table table-hover align-middle mb-0 w-100">
                    <thead class="table-light">
                        <tr>
                            <th width="48"></th>
                            <th>Code</th>
                            <th>Product</th>
                            <th>Group</th>
                            <th>Category</th>
                            <th>Unit</th>
                            <th class="text-end">Sales rate</th>
                            <th class="text-center">Condition</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function() {
    const PR_BASE  = "{{ route($routePrefix . '.index') }}";
    const PR_DELETED = {{ $showDeleted ? 'true' : 'false' }};
    const PR_EDIT   = "{{ route($routePrefix . '.edit', '__ID__') }}";
    const PR_SHOW   = "{{ route($routePrefix . '.show', '__ID__') }}";
    const PR_PRICE  = "{{ route($routePrefix . '.priceHistory', '__ID__') }}";
    const PR_RESTORE= "{{ route($routePrefix . '.restore', '__ID__') }}";
    const PR_DELETE = "{{ route($routePrefix . '.destroy', '__ID__') }}";
    const PR_CSRF   = "{{ csrf_token() }}";
    const STORAGE_URL = "{{ asset('storage') }}";

    function esc(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    function imgCell(row) {
        if (row.image) {
            const src = STORAGE_URL + '/' + row.image;
            return '<img src="' + esc(src) + '" style="width:38px;height:38px;object-fit:cover;border-radius:6px;" onerror="this.outerHTML=\'<span class=\\\'text-muted\\\'><i class=\\\'fas fa-image\\\'></i></span>\'">';
        }
        return '<span class="text-muted"><i class="fas fa-image"></i></span>';
    }
    function priceCell(row) {
        const r = parseFloat(row.sales_rate) || 0;
        if (r <= 0) return '<span class="text-muted">—</span>';
        return '<span class="badge bg-success-subtle text-success">Tk ' + r.toFixed(2) + '</span>';
    }
    function condCell(row) {
        if (!row.condition_state) return '<span class="text-muted">—</span>';
        const cls = row.condition_state === 'Good' ? 'success' : 'danger';
        return '<span class="badge bg-' + cls + '-subtle text-' + cls + '">' + esc(row.condition_state) + '</span>';
    }
    function actionsCell(row) {
        const id = row.id;
        let h = '<div class="d-flex gap-1 justify-content-center">';
        h += '<a href="' + PR_SHOW.replace('__ID__', id) + '" class="btn btn-sm btn-outline-secondary" title="View"><i class="fas fa-eye"></i></a>';
        h += '<a href="' + PR_EDIT.replace('__ID__', id) + '" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fas fa-pen"></i></a>';
        h += '<a href="' + PR_PRICE.replace('__ID__', id) + '" class="btn btn-sm btn-outline-info" title="Price history"><i class="fas fa-tag"></i></a>';
        if (PR_DELETED) {
            h += '<form method="POST" action="' + PR_RESTORE.replace('__ID__', id) + '" class="d-inline" onsubmit="return confirm(\'Restore this product?\')">'
              + '<input type="hidden" name="_token" value="' + PR_CSRF + '">'
              + '<button type="submit" class="btn btn-sm btn-outline-success" title="Restore"><i class="fas fa-rotate-left"></i></button>'
              + '</form>';
        } else {
            h += '<form method="POST" action="' + PR_DELETE.replace('__ID__', id) + '" class="d-inline" onsubmit="return confirm(\'Deactivate this product?\')">'
              + '<input type="hidden" name="_token" value="' + PR_CSRF + '">'
              + '<input type="hidden" name="_method" value="DELETE">'
              + '<button type="submit" class="btn btn-sm btn-outline-danger" title="Deactivate"><i class="fas fa-trash"></i></button>'
              + '</form>';
        }
        h += '</div>';
        return h;
    }

    $(function() {
        const table = $('#productTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: PR_BASE + (PR_DELETED ? '?deleted=1' : ''),
                data: function (d) {
                    d.filterCategory = $('#filterCategory').val();
                    d.filterGroup    = $('#filterGroup').val();
                    d.filterUnit     = $('#filterUnit').val();
                }
            },
            pageLength: 25,
            order: [[1, 'asc']],
            columns: [
                { data: 'image', orderable: false, render: (d, t, r) => imgCell(r) },
                { data: 'product_code', render: d => '<span class="badge bg-light text-dark border">' + esc(d) + '</span>' },
                { data: 'product_name', render: d => '<div class="fw-semibold">' + esc(d) + '</div>' },
                { data: 'group_name',    render: d => d ? '<span class="badge bg-purple-subtle text-purple"><i class="fas fa-globe me-1"></i>' + esc(d) + '</span>' : '<span class="text-muted">—</span>' },
                { data: 'category_name', render: d => d ? '<span class="badge bg-primary-subtle text-primary"><i class="fas fa-tag me-1"></i>' + esc(d) + '</span>' : '<span class="text-muted">—</span>' },
                { data: 'unit' },
                { data: 'sales_rate', className: 'text-end', render: (d, t, r) => priceCell(r) },
                { data: 'condition_state', className: 'text-center', orderable: false, render: (d, t, r) => condCell(r) },
                { data: 'id', orderable: false, className: 'text-center', render: (d, t, r) => actionsCell(r) }
            ]
        });

        $('#filterCategory, #filterGroup, #filterUnit').on('change', () => table.ajax.reload());
        $('#clearFilters').on('click', () => {
            $('#filterCategory,#filterGroup,#filterUnit').val('');
            table.ajax.reload();
        });
    });
})();
</script>
@endpush
