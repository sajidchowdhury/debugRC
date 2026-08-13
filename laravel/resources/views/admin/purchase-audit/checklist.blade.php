@extends('layouts.admin')

@section('content')
@php
    $sections = $report['sections'] ?? [];
    $summary  = $report['summary']  ?? [];
    $ranAt    = $report['ran_at']   ?? now()->format('Y-m-d H:i:s');
    $negativeStocks        = $report['negative_stocks']         ?? [];
    $missingGrnJournals    = $report['missing_grn_journals']    ?? [];
    $missingReturnJournals = $report['missing_return_journals'] ?? [];

    $statusIcon = function (string $st): string {
        return [
            'pass' => 'fa-check',
            'warn' => 'fa-exclamation-triangle',
            'fail' => 'fa-times',
            'info' => 'fa-info-circle',
        ][$st] ?? 'fa-info-circle';
    };
@endphp

<div class="purch-audit-app container-fluid py-2" id="purchase-audit-checklist">
    <header class="purch-audit-hero">
        <div>
            <h1><i class="fas fa-clipboard-check me-2"></i>Purchase audit checklist</h1>
            <p>Stock SSOT, GRN/return GL, supplier ledger, and data integrity — branch: {{ $branch_name }}</p>
        </div>
        <div class="d-flex gap-2 flex-shrink-0 flex-wrap">
            <button type="button" class="btn btn-light btn-sm" id="btnRefreshPurchaseAudit">
                <i class="fas fa-sync-alt me-1"></i> Re-run checks
            </button>
            <a href="{{ route('admin.purchase-audit.checklist') }}" class="btn btn-light btn-sm">
                <i class="fas fa-clipboard-check me-1"></i> This checklist
            </a>
            <a href="{{ route('admin.purchase-orders.index') }}" class="btn btn-light btn-sm">
                <i class="fas fa-file-invoice me-1"></i> PO
            </a>
            <a href="{{ route('admin.purchase-receives.index') }}" class="btn btn-light btn-sm">
                <i class="fas fa-dolly me-1"></i> GRN
            </a>
            <a href="{{ route('admin.purchase-returns.index') }}" class="btn btn-light btn-sm">
                <i class="fas fa-undo me-1"></i> Returns
            </a>
        </div>
    </header>

    <p class="purch-audit-meta" id="purchAuditMeta">
        Last run: {{ $ranAt }}
        @if (!empty($branch_id)) · Branch filter #{{ (int) $branch_id }} @endif
    </p>

    <div class="purch-audit-summary" id="purchAuditSummary">
        <span class="chip pass"><i class="fas fa-check"></i> {{ (int) ($summary['pass'] ?? 0) }} pass</span>
        <span class="chip warn"><i class="fas fa-exclamation-triangle"></i> {{ (int) ($summary['warn'] ?? 0) }} warn</span>
        <span class="chip fail"><i class="fas fa-times"></i> {{ (int) ($summary['fail'] ?? 0) }} fail</span>
        <span class="chip info"><i class="fas fa-info-circle"></i> {{ (int) ($summary['info'] ?? 0) }} reference</span>
    </div>

    <nav class="purch-audit-toc" aria-label="Sections">
        @foreach ($sections as $section)
            <a class="purch-audit-toc-link" href="#pa-section-{{ $section['id'] ?? '' }}">
                <i class="fas {{ $section['icon'] ?? 'fa-folder' }}"></i>
                {{ $section['title'] ?? '' }}
            </a>
        @endforeach
    </nav>

    <div id="purchAuditSections">
        @foreach ($sections as $section)
            <section class="purch-audit-section" id="pa-section-{{ $section['id'] ?? '' }}">
                <div class="purch-audit-section-head">
                    <i class="fas {{ $section['icon'] ?? 'fa-folder' }}"></i>
                    {{ $section['title'] ?? '' }}
                </div>
                @foreach ($section['items'] ?? [] as $item)
                    @php $st = (string) ($item['status'] ?? 'info'); @endphp
                    <article class="purch-audit-item status-{{ $st }}">
                        <div class="status-icon"><i class="fas {{ $statusIcon($st) }}"></i></div>
                        <div>
                            <h3>{{ $item['title'] ?? '' }}</h3>
                            <p class="expected">{{ $item['expected'] ?? '' }}</p>
                            @if (!empty($item['detail']))
                                <p class="detail">{{ $item['detail'] }}</p>
                            @endif
                            @if (!empty($item['url']))
                                <a href="{{ $item['url'] }}" class="btn btn-outline-primary btn-sm purch-audit-item-link mt-1">
                                    Open module
                                </a>
                            @endif
                        </div>
                        <span class="purch-audit-badge {{ $st }}">{{ $st }}</span>
                    </article>
                @endforeach
            </section>
        @endforeach
    </div>

    @if (!empty($negativeStocks))
        <section class="purch-audit-section mt-3">
            <div class="purch-audit-section-head">
                <i class="fas fa-exclamation-circle"></i> Negative warehouse stock (action required)
            </div>
            <div class="table-responsive p-2">
                <table class="table table-sm table-bordered mb-0">
                    <thead>
                        <tr>
                            <th>Warehouse</th>
                            <th>Product</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Avg cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($negativeStocks as $row)
                            <tr>
                                <td>{{ $row['warehouse_name'] ?? '' }}</td>
                                <td>{{ $row['product_name'] ?? '' }}</td>
                                <td class="text-end">{{ number_format((float) ($row['qty'] ?? 0), 2) }}</td>
                                <td class="text-end">{{ number_format((float) ($row['avg_cost'] ?? 0), 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if (!empty($missingGrnJournals))
        <section class="purch-audit-section mt-3">
            <div class="purch-audit-section-head"><i class="fas fa-dolly"></i> GRNs missing journal (sample)</div>
            <div class="table-responsive p-2">
                <table class="table table-sm table-bordered mb-0">
                    <thead><tr><th>GRN</th><th>Date</th><th class="text-end">Total</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($missingGrnJournals as $row)
                            <tr>
                                <td>{{ $row['receive_code'] ?? '' }}</td>
                                <td>{{ $row['receive_date'] ?? '' }}</td>
                                <td class="text-end">{{ number_format((float) ($row['total_amount'] ?? 0), 2) }}</td>
                                <td>
                                    <a href="{{ route('admin.purchase-receives.show', $row['id'] ?? 0) }}">GL detail</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if (!empty($missingReturnJournals))
        <section class="purch-audit-section mt-3">
            <div class="purch-audit-section-head"><i class="fas fa-undo-alt"></i> Returns missing journal (sample)</div>
            <div class="table-responsive p-2">
                <table class="table table-sm table-bordered mb-0">
                    <thead><tr><th>Return</th><th>GRN</th><th class="text-end">Total</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($missingReturnJournals as $row)
                            <tr>
                                <td>{{ $row['return_code'] ?? '' }}</td>
                                <td>{{ $row['receive_code'] ?? '' }}</td>
                                <td class="text-end">{{ number_format((float) ($row['total_amount'] ?? 0), 2) }}</td>
                                <td>
                                    <a href="{{ route('admin.purchase-returns.show', $row['id'] ?? 0) }}">GL detail</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
@endsection

@push('css')
<link rel="stylesheet" href="{{ asset('assets/css/purchase-audit-checklist.css') }}">
@endpush

@push('scripts')
<script>
(function () {
    const btn = document.getElementById('btnRefreshPurchaseAudit');
    if (!btn) return;
    const runUrl = "{{ route('admin.purchase-audit.run') }}";

    const statusIcon = (st) => ({
        pass: 'fa-check',
        warn: 'fa-exclamation-triangle',
        fail: 'fa-times',
        info: 'fa-info-circle',
    }[st] || 'fa-info-circle');

    const esc = (t) => {
        const d = document.createElement('div');
        d.textContent = t == null ? '' : String(t);
        return d.innerHTML;
    };

    btn.addEventListener('click', async function () {
        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-sync-alt fa-spin me-1"></i> Running…';
        try {
            const res = await fetch(runUrl, { credentials: 'same-origin' });
            const data = await res.json();
            if (!data || !data.sections) {
                if (window.Swal) Swal.fire('Error', 'Could not refresh audit report.', 'error');
                return;
            }

            document.getElementById('purchAuditMeta').textContent =
                'Last run: ' + (data.ran_at || '') + (data.branch_id ? ' · Branch filter #' + data.branch_id : '');

            const s = data.summary || {};
            document.getElementById('purchAuditSummary').innerHTML =
                '<span class="chip pass"><i class="fas fa-check"></i> ' + (s.pass || 0) + ' pass</span>'
                + '<span class="chip warn"><i class="fas fa-exclamation-triangle"></i> ' + (s.warn || 0) + ' warn</span>'
                + '<span class="chip fail"><i class="fas fa-times"></i> ' + (s.fail || 0) + ' fail</span>'
                + '<span class="chip info"><i class="fas fa-info-circle"></i> ' + (s.info || 0) + ' reference</span>';

            let html = '';
            data.sections.forEach((section) => {
                html += '<section class="purch-audit-section" id="pa-section-' + esc(section.id) + '">'
                    + '<div class="purch-audit-section-head"><i class="fas ' + esc(section.icon || 'fa-folder') + '"></i> '
                    + esc(section.title) + '</div>';
                (section.items || []).forEach((item) => {
                    const st = item.status || 'info';
                    html += '<article class="purch-audit-item status-' + esc(st) + '">'
                        + '<div class="status-icon"><i class="fas ' + statusIcon(st) + '"></i></div><div>'
                        + '<h3>' + esc(item.title) + '</h3><p class="expected">' + esc(item.expected) + '</p>';
                    if (item.detail) html += '<p class="detail">' + esc(item.detail) + '</p>';
                    if (item.url) {
                        html += '<a href="' + esc(item.url) + '" class="btn btn-outline-primary btn-sm purch-audit-item-link mt-1">Open module</a>';
                    }
                    html += '</div><span class="purch-audit-badge ' + esc(st) + '">' + esc(st) + '</span></article>';
                });
                html += '</section>';
            });
            document.getElementById('purchAuditSections').innerHTML = html;

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
