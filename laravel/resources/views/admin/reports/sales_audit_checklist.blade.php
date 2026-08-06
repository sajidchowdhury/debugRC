@extends('layouts.admin')

@php
    $sections        = $report['sections'] ?? [];
    $summary         = $report['summary']  ?? [];
    $ranAt           = $report['ran_at']   ?? now()->format('Y-m-d H:i:s');
    $missingGlRows   = $report['missing_gl_journals'] ?? [];
    $staleDraftRows  = $report['stale_drafts']        ?? [];

    $statusIcon = function (string $st): string {
        return [
            'pass' => 'fa-check',
            'warn' => 'fa-exclamation-triangle',
            'fail' => 'fa-times',
            'info' => 'fa-info-circle',
        ][$st] ?? 'fa-info-circle';
    };

    $statusBadgeClass = function (string $st): string {
        return [
            'pass' => 'bg-success',
            'warn' => 'bg-warning text-dark',
            'fail' => 'bg-danger',
            'info' => 'bg-info text-dark',
        ][$st] ?? 'bg-secondary';
    };
@endphp

@section('content')
<div class="container-fluid py-2" id="sales-audit-checklist">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h2 class="h4 mb-1">
                <i class="fas fa-clipboard-check me-2 text-primary"></i> Sales Audit Checklist
            </h2>
            <p class="text-muted mb-0 small">
                Invoices, challans, returns, payments, commission, customer ledger, transport,
                RLS bypass, stale drafts, GL journal links, and audit trail coverage
                — branch: <strong>{{ $branch_name ?? 'All branches' }}</strong>
            </p>
        </div>
        <div class="d-flex gap-2 flex-shrink-0 flex-wrap">
            <button type="button" class="btn btn-outline-primary btn-sm" id="btnRefreshSalesAudit">
                <i class="fas fa-sync-alt me-1"></i> Re-run checks
            </button>
            <a href="{{ route('admin.reports.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Reports hub
            </a>
        </div>
    </div>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('admin.reports.salesAuditChecklist') }}" class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">From date</label>
                    <input type="date" name="from_date" class="form-control form-control-sm"
                           value="{{ old('from_date', request('from_date', $meta['from_date'] ?? '')) }}">
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">To date</label>
                    <input type="date" name="to_date" class="form-control form-control-sm"
                           value="{{ old('to_date', request('to_date', $meta['to_date'] ?? '')) }}">
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">Branch</label>
                    <select name="branch_id" class="form-select form-select-sm">
                        <option value="">All branches</option>
                        @foreach (\App\Models\Branch::active()->orderBy('branch_name')->get(['id', 'branch_name']) as $b)
                            <option value="{{ $b->id }}"
                                @if ((string) ($branch_id ?? '') === (string) $b->id) selected @endif>
                                {{ $b->branch_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-play me-1"></i> Run
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Meta + summary chips --}}
    <p class="text-muted small mb-2" id="salesAuditMeta">
        Last run: <strong>{{ $ranAt }}</strong>
        @if (!empty($report['from']) && !empty($report['to']))
            · Period: {{ $report['from'] }} → {{ $report['to'] }}
        @endif
        @if (!empty($report['branch_id'])) · Branch filter #{{ (int) $report['branch_id'] }} @endif
    </p>

    <div class="d-flex gap-2 mb-3 flex-wrap" id="salesAuditSummary">
        <span class="badge bg-success"><i class="fas fa-check me-1"></i> {{ (int) ($summary['pass'] ?? 0) }} pass</span>
        <span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle me-1"></i> {{ (int) ($summary['warn'] ?? 0) }} warn</span>
        <span class="badge bg-danger"><i class="fas fa-times me-1"></i> {{ (int) ($summary['fail'] ?? 0) }} fail</span>
        <span class="badge bg-info text-dark"><i class="fas fa-info-circle me-1"></i> {{ (int) ($summary['info'] ?? 0) }} reference</span>
        <span class="badge bg-secondary">Total: {{ (int) ($summary['total'] ?? 0) }}</span>
    </div>

    {{-- Table of contents --}}
    <nav class="mb-3" aria-label="Sections">
        @foreach ($sections as $section)
            <a class="badge bg-light text-dark border me-1 mb-1 text-decoration-none"
               href="#sa-section-{{ $section['id'] ?? '' }}">
                <i class="fas {{ $section['icon'] ?? 'fa-folder' }} me-1"></i>
                {{ $section['title'] ?? '' }}
            </a>
        @endforeach
    </nav>

    {{-- Sections --}}
    <div id="salesAuditSections">
        @foreach ($sections as $section)
            <section class="card border-0 shadow-sm mb-3" id="sa-section-{{ $section['id'] ?? '' }}">
                <div class="card-header bg-white py-2">
                    <i class="fas {{ $section['icon'] ?? 'fa-folder' }} me-1 text-primary"></i>
                    <strong>{{ $section['title'] ?? '' }}</strong>
                </div>
                <div class="card-body p-0">
                    @foreach ($section['items'] ?? [] as $item)
                        @php $st = (string) ($item['status'] ?? 'info'); @endphp
                        <div class="d-flex align-items-start border-bottom p-2">
                            <div class="me-2 mt-1">
                                <span class="badge {{ $statusBadgeClass($st) }} rounded-circle">
                                    <i class="fas {{ $statusIcon($st) }}"></i>
                                </span>
                            </div>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-start">
                                    <strong class="small">{{ $item['title'] ?? '' }}</strong>
                                    <span class="badge {{ $statusBadgeClass($st) }} ms-2 text-uppercase">{{ $st }}</span>
                                </div>
                                <p class="text-muted mb-1 small">{{ $item['expected'] ?? '' }}</p>
                                @if (!empty($item['detail']))
                                    <p class="mb-1 small fw-semibold {{ $st === 'fail' ? 'text-danger' : ($st === 'warn' ? 'text-warning' : 'text-success') }}">
                                        {{ $item['detail'] }}
                                    </p>
                                @endif
                                @if (!empty($item['url']))
                                    <a href="{{ $item['url'] }}" class="btn btn-outline-primary btn-sm mt-1">
                                        <i class="fas fa-external-link-alt me-1"></i> Open module
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>

    {{-- Detail table: missing GL journals --}}
    @if (!empty($missingGlRows))
        <section class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white py-2">
                <i class="fas fa-file-invoice text-danger me-1"></i>
                <strong>Documents missing GL journal (sample — limit 15)</strong>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered table-striped mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="100">Type</th>
                                <th>Code</th>
                                <th>Date</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($missingGlRows as $row)
                                <tr>
                                    <td>
                                        @if (($row['doc_type'] ?? '') === 'invoice')
                                            <span class="badge bg-primary">Invoice</span>
                                        @elseif (($row['doc_type'] ?? '') === 'challan')
                                            <span class="badge bg-info text-dark">Challan</span>
                                        @elseif (($row['doc_type'] ?? '') === 'return')
                                            <span class="badge bg-warning text-dark">Return</span>
                                        @else
                                            <span class="badge bg-secondary">{{ $row['doc_type'] ?? '' }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $row['doc_code'] ?? ('#' . ($row['id'] ?? '')) }}</td>
                                    <td>{{ $row['doc_date'] ?? '' }}</td>
                                    <td class="text-end">{{ number_format((float) ($row['total_amount'] ?? 0), 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    @endif

    {{-- Detail table: stale drafts --}}
    @if (!empty($staleDraftRows))
        <section class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white py-2">
                <i class="fas fa-broom text-warning me-1"></i>
                <strong>Stale draft invoices >14 days (sample — limit 15)</strong>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered table-striped mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Invoice code</th>
                                <th>Date</th>
                                <th>Branch</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($staleDraftRows as $row)
                                <tr>
                                    <td>{{ $row['invoice_code'] ?? ('#' . ($row['id'] ?? '')) }}</td>
                                    <td>{{ $row['invoice_date'] ?? '' }}</td>
                                    <td>{{ $row['branch_id'] ?? '' }}</td>
                                    <td class="text-end">{{ number_format((float) ($row['total_amount'] ?? 0), 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    @endif
</div>
@endsection

@push('scripts')
<script>
(function () {
    const btn = document.getElementById('btnRefreshSalesAudit');
    if (!btn) return;
    const runUrl = "{{ route('admin.reports.salesAuditRun') }}";

    const statusIcon = (st) => ({
        pass: 'fa-check',
        warn: 'fa-exclamation-triangle',
        fail: 'fa-times',
        info: 'fa-info-circle',
    }[st] || 'fa-info-circle');

    const statusBadgeClass = (st) => ({
        pass: 'bg-success',
        warn: 'bg-warning text-dark',
        fail: 'bg-danger',
        info: 'bg-info text-dark',
    }[st] || 'bg-secondary');

    const esc = (t) => {
        const d = document.createElement('div');
        d.textContent = t == null ? '' : String(t);
        return d.innerHTML;
    };

    const buildSectionHtml = (section) => {
        let html = '<section class="card border-0 shadow-sm mb-3" id="sa-section-' + esc(section.id) + '">'
            + '<div class="card-header bg-white py-2">'
            + '<i class="fas ' + esc(section.icon || 'fa-folder') + ' me-1 text-primary"></i> '
            + '<strong>' + esc(section.title) + '</strong></div>'
            + '<div class="card-body p-0">';
        (section.items || []).forEach((item) => {
            const st = item.status || 'info';
            const detailColor = st === 'fail' ? 'text-danger' : (st === 'warn' ? 'text-warning' : 'text-success');
            html += '<div class="d-flex align-items-start border-bottom p-2">'
                + '<div class="me-2 mt-1"><span class="badge ' + statusBadgeClass(st) + ' rounded-circle">'
                + '<i class="fas ' + statusIcon(st) + '"></i></span></div>'
                + '<div class="flex-grow-1">'
                + '<div class="d-flex justify-content-between align-items-start">'
                + '<strong class="small">' + esc(item.title) + '</strong>'
                + '<span class="badge ' + statusBadgeClass(st) + ' ms-2 text-uppercase">' + esc(st) + '</span>'
                + '</div>'
                + '<p class="text-muted mb-1 small">' + esc(item.expected) + '</p>';
            if (item.detail) {
                html += '<p class="mb-1 small fw-semibold ' + detailColor + '">' + esc(item.detail) + '</p>';
            }
            if (item.url) {
                html += '<a href="' + esc(item.url) + '" class="btn btn-outline-primary btn-sm mt-1">'
                    + '<i class="fas fa-external-link-alt me-1"></i> Open module</a>';
            }
            html += '</div></div>';
        });
        html += '</div></section>';
        return html;
    };

    btn.addEventListener('click', async function () {
        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-sync-alt fa-spin me-1"></i> Running…';
        try {
            // Pull the current filter values from the form so the refresh
            // honours the user's date-range + branch selection.
            const params = new URLSearchParams();
            const fromInput = document.querySelector('input[name="from_date"]');
            const toInput = document.querySelector('input[name="to_date"]');
            const branchSelect = document.querySelector('select[name="branch_id"]');
            if (fromInput && fromInput.value) params.set('from_date', fromInput.value);
            if (toInput && toInput.value) params.set('to_date', toInput.value);
            if (branchSelect && branchSelect.value) params.set('branch_id', branchSelect.value);

            const url = runUrl + '?' + params.toString();
            const res = await fetch(url, { credentials: 'same-origin' });
            const data = await res.json();
            if (!data || !data.sections) {
                if (window.Swal) Swal.fire('Error', 'Could not refresh audit report.', 'error');
                return;
            }

            // Meta
            const metaEl = document.getElementById('salesAuditMeta');
            if (metaEl) {
                let metaText = 'Last run: <strong>' + esc(data.ran_at || '') + '</strong>';
                if (data.from && data.to) metaText += ' · Period: ' + esc(data.from) + ' → ' + esc(data.to);
                if (data.branch_id) metaText += ' · Branch filter #' + esc(data.branch_id);
                metaEl.innerHTML = metaText;
            }

            // Summary chips
            const sumEl = document.getElementById('salesAuditSummary');
            const s = data.summary || {};
            if (sumEl) {
                sumEl.innerHTML =
                    '<span class="badge bg-success"><i class="fas fa-check me-1"></i> ' + (s.pass || 0) + ' pass</span>'
                    + '<span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle me-1"></i> ' + (s.warn || 0) + ' warn</span>'
                    + '<span class="badge bg-danger"><i class="fas fa-times me-1"></i> ' + (s.fail || 0) + ' fail</span>'
                    + '<span class="badge bg-info text-dark"><i class="fas fa-info-circle me-1"></i> ' + (s.info || 0) + ' reference</span>'
                    + '<span class="badge bg-secondary">Total: ' + (s.total || 0) + '</span>';
            }

            // Sections
            const secEl = document.getElementById('salesAuditSections');
            if (secEl) {
                let html = '';
                (data.sections || []).forEach((section) => {
                    html += buildSectionHtml(section);
                });
                secEl.innerHTML = html;
            }

            if (window.Swal) {
                Swal.fire({
                    icon: 'success',
                    title: 'Audit refreshed',
                    text: (s.fail || 0) + ' fail · ' + (s.warn || 0) + ' warn · ' + (s.pass || 0) + ' pass',
                    timer: 1800,
                    showConfirmButton: false,
                });
            }
        } catch (e) {
            if (window.Swal) Swal.fire('Error', 'Network error while refreshing.', 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = original;
        }
    });
})();
</script>
@endpush
