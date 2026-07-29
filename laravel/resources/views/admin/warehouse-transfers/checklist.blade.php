@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#6d28d9,#7c3aed);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-clipboard-check me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">Data integrity checks for warehouse transfers — same-branch, stock movements, data quality, GL integrity</p>
        </div>
        <div>
            <a href="{{ route('admin.warehouse-transfers.index') }}" class="btn btn-sm btn-light">
                <i class="fas fa-arrow-left me-1"></i>Back to Transfers
            </a>
            <a href="{{ route('admin.warehouse-transfers.reconcile') }}" class="btn btn-sm btn-outline-light ms-1">
                <i class="fas fa-balance-scale me-1"></i>Stock Reconciliation
            </a>
        </div>
    </header>

    {{-- Run checks button --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <span class="text-muted small">Click "Run Checks" to scan all warehouse transfers for data integrity issues.</span>
        </div>
        <button id="btn-run-checks" class="btn btn-primary" onclick="runChecks()">
            <i class="fas fa-play me-1"></i>Run Checks
        </button>
    </div>

    {{-- Loading spinner --}}
    <div id="loading" class="text-center py-5 d-none">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Running checks...</span>
        </div>
        <p class="mt-2 text-muted">Running health checks...</p>
    </div>

    {{-- Results container --}}
    <div id="results" class="d-none">
        {{-- Summary bar --}}
        <div id="summary-bar" class="row g-3 mb-4"></div>

        {{-- Sections --}}
        <div id="sections-container"></div>

        {{-- Ran-at timestamp --}}
        <div id="ran-at" class="text-muted small mt-3"></div>
    </div>

    {{-- No results yet --}}
    <div id="no-results" class="text-center py-5">
        <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
        <p class="text-muted">No checks have been run yet. Click "Run Checks" above to start.</p>
    </div>
</div>
@endsection

@push('scripts')
<script>
async function runChecks() {
    const btn = document.getElementById('btn-run-checks');
    const loading = document.getElementById('loading');
    const results = document.getElementById('results');
    const noResults = document.getElementById('no-results');

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Running...';
    loading.classList.remove('d-none');
    results.classList.add('d-none');
    noResults.classList.add('d-none');

    try {
        const resp = await fetch('{{ route('admin.warehouse-transfers.run-checks') }}', {
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
        btn.innerHTML = '<i class="fas fa-play me-1"></i>Run Checks';
        loading.classList.add('d-none');
    }
}

function renderResults(data) {
    const results = document.getElementById('results');
    const summaryBar = document.getElementById('summary-bar');
    const sectionsContainer = document.getElementById('sections-container');
    const ranAt = document.getElementById('ran-at');

    // Summary bar
    const s = data.summary;
    summaryBar.innerHTML = `
        <div class="col-auto"><div class="card border-success"><div class="card-body text-center py-2 px-3">
            <div class="h4 mb-0 text-success">${s.pass}</div><div class="small text-muted">Pass</div>
        </div></div></div>
        <div class="col-auto"><div class="card border-warning"><div class="card-body text-center py-2 px-3">
            <div class="h4 mb-0 text-warning">${s.warn}</div><div class="small text-muted">Warn</div>
        </div></div></div>
        <div class="col-auto"><div class="card border-danger"><div class="card-body text-center py-2 px-3">
            <div class="h4 mb-0 text-danger">${s.fail}</div><div class="small text-muted">Fail</div>
        </div></div></div>
        <div class="col-auto"><div class="card border-info"><div class="card-body text-center py-2 px-3">
            <div class="h4 mb-0 text-info">${s.info}</div><div class="small text-muted">Info</div>
        </div></div></div>
    `;

    // Sections
    sectionsContainer.innerHTML = '';
    data.sections.forEach(section => {
        let itemsHtml = '';
        section.items.forEach(item => {
            const statusIcon = {
                'pass': '<i class="fas fa-check-circle text-success"></i>',
                'warn': '<i class="fas fa-exclamation-triangle text-warning"></i>',
                'fail': '<i class="fas fa-times-circle text-danger"></i>',
                'info': '<i class="fas fa-info-circle text-info"></i>',
            }[item.status] || '<i class="fas fa-question-circle text-muted"></i>';

            const typeBadge = item.type === 'auto'
                ? '<span class="badge bg-secondary-subtle text-secondary me-1">auto</span>'
                : '<span class="badge bg-light text-muted me-1">ref</span>';

            itemsHtml += `
                <tr>
                    <td class="text-center">${statusIcon}</td>
                    <td>${typeBadge}${item.title}</td>
                    <td class="text-muted small">${item.expected}</td>
                    <td>${item.detail}</td>
                </tr>
            `;
        });

        sectionsContainer.innerHTML += `
            <div class="card mb-3">
                <div class="card-header bg-light">
                    <i class="fas ${section.icon} me-2"></i><strong>${section.title}</strong>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead><tr>
                            <th style="width:40px"></th>
                            <th>Check</th>
                            <th>Expected</th>
                            <th>Result</th>
                        </tr></thead>
                        <tbody>${itemsHtml}</tbody>
                    </table>
                </div>
            </div>
        `;
    });

    // Timestamp
    ranAt.textContent = 'Last checked: ' + data.ran_at + (data.branch_id ? ' (Branch #' + data.branch_id + ')' : ' (All branches)');

    results.classList.remove('d-none');
}
</script>
@endpush
