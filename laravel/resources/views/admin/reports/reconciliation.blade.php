@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-scale-balanced me-2 text-primary"></i> GL Reconciliation</h2>
            <p class="text-muted mb-0 small">
                Last run: {{ \Carbon\Carbon::parse($run_at)->format('d M Y, H:i:s') }} &middot;
                Tolerance: Tk {{ number_format($tolerance, 2) }}
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.reports.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Reports
            </a>
            <button id="refreshBtn" class="btn btn-primary btn-sm">
                <i class="fas fa-rotate me-1"></i> Refresh
            </button>
        </div>
    </div>

    {{-- All-green / red banner --}}
    <div id="reconBanner" class="alert {{ $all_green ? 'alert-success' : 'alert-danger' }} d-flex justify-content-between align-items-center">
        <span>
            <i class="fas {{ $all_green ? 'fa-check-circle' : 'fa-exclamation-triangle' }} me-1"></i>
            @if ($all_green)
                <strong>All 6 sections reconciled.</strong> GL is in balance with all sub-ledgers.
            @else
                <strong>Reconciliation issues detected.</strong> One or more sections have variances exceeding the tolerance of Tk {{ number_format($tolerance, 2) }}.
            @endif
        </span>
        <span class="badge {{ $all_green ? 'bg-success' : 'bg-danger' }} fs-6">
            {{ collect($sections)->where('status', 'green')->count() }} / {{ count($sections) }} green
        </span>
    </div>

    {{-- 2x3 grid of section cards --}}
    <div id="reconGrid" class="row g-3">
        @foreach ($sections as $s)
            @php
                $statusClass = $s['status'] === 'green' ? 'success'
                             : ($s['status'] === 'red' ? 'danger' : 'secondary');
                $statusLabel = $s['status'] === 'green' ? 'OK'
                             : ($s['status'] === 'red' ? 'Variance exceeds tolerance' : 'Query failed');
                $statusIcon  = $s['status'] === 'green' ? 'fa-check-circle'
                             : ($s['status'] === 'red' ? 'fa-exclamation-triangle' : 'fa-times-circle');
            @endphp
            <div class="col-md-6 col-xl-4" data-section="{{ $s['id'] }}">
                <div class="card border-0 shadow-sm h-100 border-start border-{{ $statusClass }} border-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div class="d-flex align-items-center">
                                <span class="rounded-3 d-flex align-items-center justify-content-center text-white me-2"
                                      style="width:42px;height:42px;background:#6366f1;">
                                    <i class="fas {{ $s['icon'] }}"></i>
                                </span>
                                <div>
                                    <div class="fw-semibold">{{ $s['label'] }}</div>
                                    <small class="text-muted text-uppercase">{{ $s['id'] }}</small>
                                </div>
                            </div>
                            <span class="badge bg-{{ $statusClass }}">
                                <i class="fas {{ $statusIcon }} me-1"></i>{{ $s['status'] === 'green' ? 'OK' : ($s['status'] === 'red' ? 'OUT' : 'ERROR') }}
                            </span>
                        </div>

                        @if ($s['status'] === 'error' && !empty($s['error']))
                            <div class="alert alert-danger py-1 px-2 small mb-0">
                                <i class="fas fa-bug me-1"></i> {{ $s['error'] }}
                            </div>
                        @else
                            <table class="table table-sm table-borderless mb-0">
                                <tbody>
                                    <tr>
                                        <td class="text-muted small ps-0">Sub-ledger total</td>
                                        <td class="text-end fw-semibold">Tk {{ number_format($s['subledger_total'], 2) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted small ps-0">GL control total</td>
                                        <td class="text-end fw-semibold">Tk {{ number_format($s['gl_control_total'], 2) }}</td>
                                    </tr>
                                    <tr class="border-top">
                                        <td class="ps-0 fw-semibold small">Variance</td>
                                        <td class="text-end fw-bold text-{{ $statusClass }}">
                                            Tk {{ number_format($s['variance'], 2) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <div class="text-end mt-1">
                                <small class="text-muted">{{ $statusLabel }}</small>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Tolerance footnote --}}
    <div class="mt-3 text-muted small">
        <i class="fas fa-info-circle me-1"></i>
        Variance tolerance: <strong>Tk {{ number_format($tolerance, 2) }}</strong>.
        Sections with a variance ≤ tolerance are marked OK. The reconciliation hub must be all-green before an accounting period can be closed.
    </div>
</div>
@endsection

@push('scripts')
<script>
(function() {
    const REFRESH_URL = "{{ route('admin.reconciliation.refresh') }}";
    const btn = document.getElementById('refreshBtn');
    if (!btn) return;

    btn.addEventListener('click', function() {
        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Refreshing...';

        fetch(REFRESH_URL, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.sections) {
                Swal.fire('Error', 'Unexpected response from server.', 'error');
                return;
            }
            // Update banner
            const banner = document.getElementById('reconBanner');
            if (data.all_green) {
                banner.className = 'alert alert-success d-flex justify-content-between align-items-center';
                banner.innerHTML = '<span><i class="fas fa-check-circle me-1"></i><strong>All 6 sections reconciled.</strong> GL is in balance with all sub-ledgers.</span>' +
                    '<span class="badge bg-success fs-6">' + data.sections.filter(s => s.status === 'green').length + ' / ' + data.sections.length + ' green</span>';
            } else {
                banner.className = 'alert alert-danger d-flex justify-content-between align-items-center';
                banner.innerHTML = '<span><i class="fas fa-exclamation-triangle me-1"></i><strong>Reconciliation issues detected.</strong> One or more sections have variances exceeding tolerance.</span>' +
                    '<span class="badge bg-danger fs-6">' + data.sections.filter(s => s.status === 'green').length + ' / ' + data.sections.length + ' green</span>';
            }
            Swal.fire('Reconciled', 'Reconciliation re-run successfully.', 'success');
        })
        .catch(err => {
            console.error(err);
            Swal.fire('Error', 'Failed to refresh reconciliation.', 'error');
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = original;
        });
    });
})();
</script>
@endpush
