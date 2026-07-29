@extends('layouts.admin')

@section('content')
@php
    // Admin sees the "Rebuild Snapshot" button (destructive maintenance op).
    $canRebuild = auth()->user()?->isAdmin() ?? false;
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0f766e,#0891b2);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-scale-balanced me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Verify that <code>SUM(stock_transactions.qty)</code> = <code>warehouse_stock.qty</code> for every warehouse × product. Drift means the snapshot cache diverged from the immutable ledger (SSOT).
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.stock-adjustments.audit') }}" class="btn btn-sm btn-outline-light">
                <i class="fas fa-clipboard-check me-1"></i>Audit
            </a>
            <a href="{{ route('admin.stock-adjustments.index') }}" class="btn btn-sm btn-light">
                <i class="fas fa-arrow-left me-1"></i>Back to Adjustments
            </a>
        </div>
    </header>

    {{-- Controls: warehouse scope + run --}}
    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-end gap-3">
            <div class="d-flex flex-wrap gap-3 align-items-end">
                <div>
                    <label for="warehouse-filter" class="form-label small text-muted mb-1">Warehouse scope</label>
                    <select id="warehouse-filter" class="form-select form-select-sm" style="min-width:220px;">
                        <option value="">All warehouses (my branch)</option>
                        @foreach ($warehouses as $wh)
                            <option value="{{ $wh->id }}">{{ $wh->warehouse_name }} ({{ $wh->warehouse_code }})</option>
                        @endforeach
                    </select>
                </div>
                @if ($userBranchId)
                    <div class="small text-muted align-self-center">
                        <i class="fas fa-lock me-1"></i>Scoped to your branch (non-admin).
                    </div>
                @else
                    <div class="small text-muted align-self-center">
                        <i class="fas fa-eye me-1"></i>All branches (admin).
                    </div>
                @endif
            </div>
            <div class="d-flex gap-2">
                <button id="btn-reconcile" class="btn btn-info btn-sm" onclick="runReconcile()">
                    <i class="fas fa-play me-1"></i>Run Reconciliation
                </button>
                @if ($canRebuild)
                <button id="btn-rebuild" class="btn btn-outline-danger btn-sm" onclick="confirmRebuild()">
                    <i class="fas fa-wrench me-1"></i>Rebuild Snapshot
                </button>
                @endif
            </div>
        </div>
    </div>

    {{-- Loading spinner --}}
    <div id="loading" class="text-center py-5 d-none">
        <div class="spinner-border text-info" role="status">
            <span class="visually-hidden">Running reconciliation...</span>
        </div>
        <p class="mt-2 text-muted">Verifying stock invariant…</p>
    </div>

    {{-- Results container --}}
    <div id="results" class="d-none">
        {{-- Summary --}}
        <div id="summary-bar" class="row g-3 mb-3"></div>

        {{-- Mismatches table --}}
        <div class="card mb-4">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <strong><i class="fas fa-triangle-exclamation me-2"></i>Stock Drift (warehouse_stock ≠ ledger)</strong>
                <span id="mismatch-count" class="badge bg-warning"></span>
            </div>
            <div class="card-body p-0">
                <div id="mismatches-table" style="max-height:560px; overflow:auto;"></div>
            </div>
        </div>

        {{-- Ran-at timestamp --}}
        <div id="ran-at" class="text-muted small mt-2"></div>
    </div>

    {{-- No results yet --}}
    <div id="no-results" class="text-center py-5">
        <i class="fas fa-scale-balanced fa-3x text-muted mb-3"></i>
        <p class="text-muted">No reconciliation has been run yet. Click <strong>Run Reconciliation</strong> above to start.</p>
    </div>
</div>
@endsection

@push('scripts')
<script>
async function runReconcile() {
    const btn = document.getElementById('btn-reconcile');
    const loading = document.getElementById('loading');
    const results = document.getElementById('results');
    const noResults = document.getElementById('no-results');
    const warehouseId = document.getElementById('warehouse-filter').value;

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Running…';
    loading.classList.remove('d-none');
    results.classList.add('d-none');
    noResults.classList.add('d-none');

    try {
        const body = new FormData();
        if (warehouseId) body.append('warehouse_id', warehouseId);

        const resp = await fetch('{{ route('admin.stock-adjustments.run-reconcile') }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: body,
        });

        if (!resp.ok) {
            const err = await resp.json();
            throw new Error(err.error || 'Request failed');
        }

        const data = await resp.json();
        renderResults(data);
    } catch (e) {
        alert('Error: ' + e.message);
        noResults.classList.remove('d-none');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-play me-1"></i>Run Reconciliation';
        loading.classList.add('d-none');
    }
}

function renderResults(data) {
    const results = document.getElementById('results');
    const summaryBar = document.getElementById('summary-bar');
    const mismatchCount = document.getElementById('mismatch-count');
    const mismatchesTable = document.getElementById('mismatches-table');
    const ranAt = document.getElementById('ran-at');

    const isClean = data.mismatched === 0;
    const fmt = (n) => Number(n).toFixed(4);

    summaryBar.innerHTML = `
        <div class="col-auto"><div class="card ${isClean ? 'border-success' : 'border-warning'}"><div class="card-body text-center py-2 px-3">
            <div class="h4 mb-0 ${isClean ? 'text-success' : 'text-warning'}">${data.checked}</div><div class="small text-muted">Rows Checked</div>
        </div></div></div>
        <div class="col-auto"><div class="card ${isClean ? 'border-success' : 'border-danger'}"><div class="card-body text-center py-2 px-3">
            <div class="h4 mb-0 ${isClean ? 'text-success' : 'text-danger'}">${data.mismatched}</div><div class="small text-muted">Mismatches</div>
        </div></div></div>
        <div class="col-auto"><div class="card ${isClean ? 'border-success' : 'border-danger'}"><div class="card-body text-center py-2 px-3">
            <div class="h4 mb-0 ${isClean ? 'text-success' : 'text-danger'}">${fmt(data.total_drift_qty)}</div><div class="small text-muted">Total |Drift Qty|</div>
        </div></div></div>
    `;

    mismatchCount.textContent = data.mismatched + ' mismatch(es)';

    if (data.mismatches.length === 0) {
        mismatchesTable.innerHTML = `
            <div class="text-center py-4">
                <i class="fas fa-check-circle text-success fa-2x me-2"></i>
                <span class="text-success">All stock balances are consistent — warehouse_stock matches the ledger (SSOT).</span>
            </div>
        `;
    } else {
        let rows = '';
        data.mismatches.forEach(m => {
            const drift = Number(m.drift_qty);
            const driftCls = drift > 0 ? 'text-danger' : 'text-warning';
            rows += `
                <tr>
                    <td>${escapeHtml(m.warehouse_name || ('WH #' + m.warehouse_id))}</td>
                    <td>${escapeHtml(m.product_name || ('Product #' + m.product_id))}<br><small class="text-muted">${escapeHtml(m.product_code || '')}</small></td>
                    <td class="text-end">${fmt(m.snapshot_qty)}</td>
                    <td class="text-end">${fmt(m.ledger_qty)}</td>
                    <td class="text-end fw-bold ${driftCls}">${fmt(m.drift_qty)}</td>
                </tr>
            `;
        });
        mismatchesTable.innerHTML = `
            <table class="table table-sm table-hover mb-0">
                <thead class="sticky-top"><tr>
                    <th>Warehouse</th>
                    <th>Product</th>
                    <th class="text-end">Snapshot qty</th>
                    <th class="text-end">Ledger qty</th>
                    <th class="text-end">Drift</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    ranAt.textContent = 'Last checked: ' + data.ran_at;
    results.classList.remove('d-none');
}

function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

@if ($canRebuild)
async function confirmRebuild() {
    const warehouseId = document.getElementById('warehouse-filter').value;
    const scope = warehouseId ? ('warehouse #' + warehouseId) : 'ALL warehouses';
    const ok = confirm(
        'Rebuild the warehouse_stock snapshot from the stock_transactions ledger?\n\n' +
        'Scope: ' + scope + '\n\n' +
        'This is a destructive maintenance op: every warehouse_stock row in scope will be DELETEd and recomputed from the ledger (the SSOT). It is safe — the ledger is append-only and never mutated by application code — but it will briefly lock stock movements for the scope.\n\n' +
        'Continue?'
    );
    if (!ok) return;

    const btn = document.getElementById('btn-rebuild');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Rebuilding…';

    try {
        const body = new FormData();
        if (warehouseId) body.append('warehouse_id', warehouseId);

        const resp = await fetch('{{ route('admin.stock-adjustments.rebuild-snapshot') }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: body,
        });

        const data = await resp.json();
        if (!resp.ok) {
            throw new Error(data.error || 'Rebuild failed');
        }

        alert(data.message);

        // Auto-run reconciliation after a rebuild so the admin sees the drift
        // clear (or shrink to tolerance noise).
        runReconcile();
    } catch (e) {
        alert('Error: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-wrench me-1"></i>Rebuild Snapshot';
    }
}
@endif
</script>
@endpush
