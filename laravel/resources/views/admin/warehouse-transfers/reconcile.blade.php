@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0891b2,#0e7490);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-balance-scale me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">Verify that SUM(stock_transactions.qty) = warehouse_stock.qty for every warehouse × product</p>
        </div>
        <div>
            <a href="{{ route('admin.warehouse-transfers.checklist') }}" class="btn btn-sm btn-outline-light">
                <i class="fas fa-clipboard-check me-1"></i>Audit Checklist
            </a>
            <a href="{{ route('admin.warehouse-transfers.index') }}" class="btn btn-sm btn-light ms-1">
                <i class="fas fa-arrow-left me-1"></i>Back to Transfers
            </a>
        </div>
    </header>

    {{-- Run reconciliation button --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <span class="text-muted small">Click "Run Reconciliation" to verify stock integrity across all warehouses.</span>
        </div>
        <button id="btn-reconcile" class="btn btn-info" onclick="runReconcile()">
            <i class="fas fa-play me-1"></i>Run Reconciliation
        </button>
    </div>

    {{-- Loading spinner --}}
    <div id="loading" class="text-center py-5 d-none">
        <div class="spinner-border text-info" role="status">
            <span class="visually-hidden">Running reconciliation...</span>
        </div>
        <p class="mt-2 text-muted">Verifying stock invariant...</p>
    </div>

    {{-- Results container --}}
    <div id="results" class="d-none">
        {{-- Summary --}}
        <div id="summary-bar" class="row g-3 mb-4"></div>

        {{-- Mismatches table --}}
        <div class="card mb-4">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <strong><i class="fas fa-exclamation-triangle me-2"></i>Stock Mismatches</strong>
                <span id="mismatch-count" class="badge bg-warning"></span>
            </div>
            <div class="card-body p-0">
                <div id="mismatches-table"></div>
            </div>
        </div>

        {{-- Ran-at timestamp --}}
        <div id="ran-at" class="text-muted small mt-3"></div>
    </div>

    {{-- No results yet --}}
    <div id="no-results" class="text-center py-5">
        <i class="fas fa-balance-scale fa-3x text-muted mb-3"></i>
        <p class="text-muted">No reconciliation has been run yet. Click "Run Reconciliation" above to start.</p>
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

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Running...';
    loading.classList.remove('d-none');
    results.classList.add('d-none');
    noResults.classList.add('d-none');

    try {
        const resp = await fetch('{{ route('admin.warehouse-transfers.run-reconcile') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
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

    // Summary
    const isClean = data.mismatched === 0;
    summaryBar.innerHTML = `
        <div class="col-auto"><div class="card ${isClean ? 'border-success' : 'border-warning'}"><div class="card-body text-center py-2 px-3">
            <div class="h4 mb-0 ${isClean ? 'text-success' : 'text-warning'}">${data.checked}</div><div class="small text-muted">Rows Checked</div>
        </div></div></div>
        <div class="col-auto"><div class="card ${isClean ? 'border-success' : 'border-danger'}"><div class="card-body text-center py-2 px-3">
            <div class="h4 mb-0 ${isClean ? 'text-success' : 'text-danger'}">${data.mismatched}</div><div class="small text-muted">Mismatches</div>
        </div></div></div>
    `;

    mismatchCount.textContent = data.mismatched + ' mismatch(es)';

    if (data.mismatches.length === 0) {
        mismatchesTable.innerHTML = `
            <div class="text-center py-4">
                <i class="fas fa-check-circle text-success fa-2x me-2"></i>
                <span class="text-success">All stock balances are consistent — no mismatches found.</span>
            </div>
        `;
    } else {
        let rows = '';
        data.mismatches.forEach(m => {
            rows += `
                <tr>
                    <td>${m.warehouse_name || 'WH #' + m.warehouse_id}</td>
                    <td>${m.product_name || 'Product #' + m.product_id}</td>
                    <td class="text-end">${Number(m.stock_qty).toFixed(4)}</td>
                    <td class="text-end">${Number(m.transaction_sum).toFixed(4)}</td>
                    <td class="text-end fw-bold ${Number(m.difference) > 0 ? 'text-danger' : 'text-warning'}">${Number(m.difference).toFixed(4)}</td>
                </tr>
            `;
        });
        mismatchesTable.innerHTML = `
            <table class="table table-sm table-hover mb-0">
                <thead><tr>
                    <th>Warehouse</th>
                    <th>Product</th>
                    <th class="text-end">Stock Qty</th>
                    <th class="text-end">Transaction Sum</th>
                    <th class="text-end">Difference</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    ranAt.textContent = 'Last checked: ' + data.ran_at;

    results.classList.remove('d-none');
}
</script>
@endpush
